<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\Connection;
use App\Domain\Chatbots\ChatbotListQuery;
use App\Domain\Chatbots\ChatbotAssignments;
use App\Domain\Chatbots\ChatbotListSort;
use App\Domain\Chatbots\ChatbotListStatus;
use App\Domain\Chatbots\ChatbotPublicationFilter;
use App\Domain\Chatbots\ChatbotStatus;
use App\Exceptions\StaleChatbotDraftException;
use App\Exceptions\UnchangedChatbotPublicationException;
use App\Exceptions\ChatbotPublicationReadinessException;
use App\Repositories\PdoChatbotRepository;
use App\Support\Config;
use App\Support\Pagination\PageRequest;
use App\Support\SortDirection;
use Tests\Support\ChatbotFixtures;
use Tests\Support\DatabaseIntegrationTestCase;
use Tests\Support\TestDatabase;

final class ChatbotRepositoryTest extends DatabaseIntegrationTestCase
{
    public function testDraftPublicationLifecycleIsTransactionalAndListProjectionIsBounded(): void
    {
        $repository = $this->repository();
        $sourceId = $this->createCompatibleSourceWithNewerPendingVersion('Handbook');
        $chatbot = $repository->create('cb_integration', 'Support', 'Internal', ChatbotFixtures::draft());
        self::assertSame(1, $chatbot->draft->revision);
        self::assertNull($chatbot->activePublicationId);

        $chatbot = $repository->replaceDraftAssignments(
            $chatbot->id,
            1,
            new ChatbotAssignments([$sourceId], ['https://example.com']),
        );
        self::assertSame(2, $chatbot->draft->revision);

        $publication = $repository->publish(
            $chatbot->id,
            2,
            $chatbot->draft,
            $chatbot->assignments,
            ChatbotFixtures::provider(),
            str_repeat('a', 64),
        );
        self::assertSame(1, $publication->publicationNumber);
        self::assertSame($publication->id, $repository->findById($chatbot->id)?->activePublicationId);

        try {
            $repository->publish(
                $chatbot->id,
                2,
                $chatbot->draft,
                $chatbot->assignments,
                ChatbotFixtures::provider(),
                str_repeat('a', 64),
            );
            self::fail('The locked publication transaction must reject an unchanged active snapshot.');
        } catch (UnchangedChatbotPublicationException) {
            self::assertSame(1, $repository->findActivePublication($chatbot->id)?->publicationNumber);
        }

        $updated = $repository->updateDraft(
            $chatbot->id,
            2,
            'Support updated',
            null,
            ChatbotFixtures::draft(displayName: 'Updated assistant'),
        );
        self::assertSame(3, $updated->draft->revision);
        self::assertSame(
            'Support assistant',
            $repository->findActivePublication($chatbot->id)?->configuration->presentation['display_name'],
        );

        $result = $repository->paginate(new ChatbotListQuery(
            new PageRequest(),
            'Support',
            ChatbotListStatus::Active,
            ChatbotPublicationFilter::Published,
            ChatbotListSort::Updated,
            SortDirection::Descending,
        ));
        self::assertSame(1, $result->total);
        self::assertSame('gpt-test-chat', $result->items[0]->chatModel);
        self::assertSame(3, $result->items[0]->draftRevision);

        $repository->replaceDraftAssignments(
            $chatbot->id,
            3,
            new ChatbotAssignments([$sourceId], ['https://new.example.com']),
        );
        self::assertSame(
            ['https://example.com'],
            $repository->findActivePublication($chatbot->id)?->assignments->origins,
            'The active publication must retain its immutable origin snapshot.',
        );
        $dependencies = $repository->sourceDependencies($sourceId);
        self::assertCount(1, $dependencies);
        self::assertTrue($dependencies[0]->inDraft);
        self::assertTrue($dependencies[0]->inActivePublication);
        self::assertSame(1, $dependencies[0]->publicationCount);

        $rotated = $repository->rotatePublicId($chatbot->id, 'cb_rotated');
        self::assertSame('cb_rotated', $rotated->publicId);
        self::assertNull($repository->findByPublicId('cb_integration'));
    }

    public function testPublicationRejectsTheActiveVersionWhenItsEmbeddingIsIncompatible(): void
    {
        $repository = $this->repository();
        $sourceId = $this->createCompatibleSourceWithNewerPendingVersion('Incompatible');
        self::$database?->prepare(
            'UPDATE source_chunks sc
             INNER JOIN sources s ON s.active_version_id = sc.source_version_id
             SET sc.embedding_model = :model WHERE s.id = :source_id',
        )->execute(['model' => 'other-model', 'source_id' => $sourceId]);
        $chatbot = $repository->create('cb_incompatible', 'Incompatible', null, ChatbotFixtures::draft());
        $chatbot = $repository->replaceDraftAssignments(
            $chatbot->id,
            1,
            new ChatbotAssignments([$sourceId], ['https://example.com']),
        );

        try {
            $repository->publish(
                $chatbot->id,
                2,
                $chatbot->draft,
                $chatbot->assignments,
                ChatbotFixtures::provider(),
                str_repeat('b', 64),
            );
            self::fail('An embedding-incompatible active version must not be published.');
        } catch (ChatbotPublicationReadinessException $exception) {
            self::assertSame('embedding_incompatible', $exception->sources[0]->status->value);
            self::assertNull($repository->findById($chatbot->id)?->activePublicationId);
        }
    }

    public function testOptimisticDraftRevisionAndConfirmedLifecycleDeletion(): void
    {
        $repository = $this->repository();
        $chatbot = $repository->create('cb_lifecycle', 'Lifecycle', null, ChatbotFixtures::draft());
        $repository->updateDraft($chatbot->id, 1, 'Lifecycle', null, ChatbotFixtures::draft(displayName: 'Version two'));

        try {
            $repository->updateDraft($chatbot->id, 1, 'Lifecycle', null, ChatbotFixtures::draft());
            self::fail('A stale expected revision must not overwrite a newer draft.');
        } catch (StaleChatbotDraftException) {
            self::assertSame(2, $repository->findById($chatbot->id)?->draft->revision);
        }

        $repository->setStatus($chatbot->id, ChatbotStatus::Archived);
        $repository->permanentlyDelete($chatbot->id);
        self::assertNull($repository->findById($chatbot->id));
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$database?->exec('UPDATE chatbots SET active_publication_id = NULL');
        self::$database?->exec('DELETE FROM chatbot_publications');
        self::$database?->exec('DELETE FROM chatbots');
        self::$database?->exec('UPDATE sources SET active_version_id = NULL');
        self::$database?->exec('DELETE FROM source_versions');
        self::$database?->exec('DELETE FROM sources');
    }

    private function repository(): PdoChatbotRepository
    {
        $environment = TestDatabase::applicationEnvironment();
        $config = new Config(['database' => [
            'host' => $environment['DB_HOST'],
            'port' => (int) $environment['DB_PORT'],
            'database' => $environment['DB_DATABASE'],
            'username' => $environment['DB_USERNAME'],
            'password' => $environment['DB_PASSWORD'],
            'charset' => 'utf8mb4',
        ]]);

        return new PdoChatbotRepository(new Connection($config));
    }

    private function createCompatibleSourceWithNewerPendingVersion(string $name): int
    {
        $statement = self::$database?->prepare(
            "INSERT INTO sources (name, source_type, status) VALUES (:name, 'markdown', 'enabled')",
        );
        $statement?->execute(['name' => $name]);
        $sourceId = (int) self::$database?->lastInsertId();
        $version = self::$database?->prepare(
            "INSERT INTO source_versions
                (source_id, version_number, processing_status, content_hash, extracted_text, processed_at, activated_at)
             VALUES (:source_id, :version_number, :status, :content_hash, :text, UTC_TIMESTAMP(6), :activated_at)",
        );
        $version?->execute([
            'source_id' => $sourceId,
            'version_number' => 1,
            'status' => 'ready',
            'content_hash' => str_repeat('c', 64),
            'text' => 'Ready content',
            'activated_at' => '2026-07-20 00:00:00.000000',
        ]);
        $activeVersionId = (int) self::$database?->lastInsertId();
        self::$database?->prepare('UPDATE sources SET active_version_id = :version_id WHERE id = :source_id')
            ->execute(['version_id' => $activeVersionId, 'source_id' => $sourceId]);
        $chunk = self::$database?->prepare(
            'INSERT INTO source_chunks
                (source_version_id, chunk_number, content, token_count, embedding, embedding_model,
                 embedding_dimensions, embedded_at, metadata_json)
             VALUES (:version_id, 1, :content, 2, :embedding, :model, 1536, UTC_TIMESTAMP(6), :metadata)',
        );
        $chunk?->execute([
            'version_id' => $activeVersionId,
            'content' => 'Ready content',
            'embedding' => '[0.1]',
            'model' => 'text-embedding-test',
            'metadata' => '{}',
        ]);
        $version?->execute([
            'source_id' => $sourceId,
            'version_number' => 2,
            'status' => 'pending',
            'content_hash' => null,
            'text' => null,
            'activated_at' => null,
        ]);

        return $sourceId;
    }
}
