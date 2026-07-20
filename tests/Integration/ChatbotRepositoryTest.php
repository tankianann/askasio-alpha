<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\Connection;
use App\Domain\Chatbots\ChatbotListQuery;
use App\Domain\Chatbots\ChatbotListSort;
use App\Domain\Chatbots\ChatbotListStatus;
use App\Domain\Chatbots\ChatbotPublicationFilter;
use App\Domain\Chatbots\ChatbotStatus;
use App\Exceptions\StaleChatbotDraftException;
use App\Exceptions\UnchangedChatbotPublicationException;
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
        $chatbot = $repository->create('cb_integration', 'Support', 'Internal', ChatbotFixtures::draft());
        self::assertSame(1, $chatbot->draft->revision);
        self::assertNull($chatbot->activePublicationId);

        $publication = $repository->publish(
            $chatbot->id,
            1,
            $chatbot->draft,
            ChatbotFixtures::provider(),
            str_repeat('a', 64),
        );
        self::assertSame(1, $publication->publicationNumber);
        self::assertSame($publication->id, $repository->findById($chatbot->id)?->activePublicationId);

        try {
            $repository->publish(
                $chatbot->id,
                1,
                $chatbot->draft,
                ChatbotFixtures::provider(),
                str_repeat('a', 64),
            );
            self::fail('The locked publication transaction must reject an unchanged active snapshot.');
        } catch (UnchangedChatbotPublicationException) {
            self::assertSame(1, $repository->findActivePublication($chatbot->id)?->publicationNumber);
        }

        $updated = $repository->updateDraft(
            $chatbot->id,
            1,
            'Support updated',
            null,
            ChatbotFixtures::draft(displayName: 'Updated assistant'),
        );
        self::assertSame(2, $updated->draft->revision);
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
        self::assertSame(2, $result->items[0]->draftRevision);

        $rotated = $repository->rotatePublicId($chatbot->id, 'cb_rotated');
        self::assertSame('cb_rotated', $rotated->publicId);
        self::assertNull($repository->findByPublicId('cb_integration'));
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
}
