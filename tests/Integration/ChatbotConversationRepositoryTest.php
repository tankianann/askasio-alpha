<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\Connection;
use App\Domain\Chatbots\ChatbotAssignments;
use App\Domain\Chatbots\ChatbotMessageCompletion;
use App\Domain\Chatbots\ChatbotMessageReservationState;
use App\Domain\Chatbots\ChatbotSessionChannel;
use App\Domain\Chatbots\ChatbotSessionCredentials;
use App\Repositories\PdoChatbotConversationRepository;
use App\Repositories\PdoChatbotRepository;
use App\Services\Chatbots\ChatbotConversationService;
use App\Services\Chatbots\ChatbotSessionCredentialGeneratorInterface;
use App\Support\Config;
use DateTimeImmutable;
use PDO;
use Tests\Support\ChatbotFixtures;
use Tests\Support\DatabaseIntegrationTestCase;
use Tests\Support\TestDatabase;

final class ChatbotConversationRepositoryTest extends DatabaseIntegrationTestCase
{
    public function testDraftPreviewSessionPersistsAnImmutableTestConfigurationSnapshot(): void
    {
        $connection = $this->connection();
        $chatbots = new PdoChatbotRepository($connection);
        $sourceId = $this->createReadySource();
        $chatbot = $chatbots->create('cb_draft_preview', 'Draft preview', null, ChatbotFixtures::draft());
        $chatbot = $chatbots->replaceDraftAssignments(
            $chatbot->id,
            1,
            new ChatbotAssignments([$sourceId], ['https://example.com']),
        );
        $token = 'cst_v1_' . str_repeat('P', 43);
        $credentials = new ChatbotSessionCredentials(
            'cs_' . str_repeat('Q', 43),
            $token,
            substr($token, 0, 15),
            hash('sha256', $token),
        );
        $generator = new class($credentials) implements ChatbotSessionCredentialGeneratorInterface {
            public function __construct(private readonly ChatbotSessionCredentials $credentials)
            {
            }

            public function generate(): ChatbotSessionCredentials
            {
                return $this->credentials;
            }
        };
        $repository = new PdoChatbotConversationRepository($connection);
        $service = new ChatbotConversationService($repository, $generator);
        $created = $service->createDraftPreviewSession(
            $chatbot,
            ChatbotFixtures::provider(),
            new DateTimeImmutable('2026-07-20 09:00:00 UTC'),
        );

        self::assertNull($created->session->publicationId);
        self::assertSame(2, $created->session->previewDraftRevision);
        self::assertSame(ChatbotSessionChannel::AdminPreview, $created->session->channel);
        self::assertTrue($created->session->isTest);
        $configuration = $repository->previewExecutionConfiguration($created->session->id);
        self::assertNotNull($configuration);
        self::assertSame([$sourceId], $configuration->assignments->sourceIds);
        self::assertSame($chatbot->draft->configuration(), $configuration->configuration->configuration());
        self::assertSame(
            ChatbotFixtures::provider()->configuration(),
            $configuration->providerConfiguration->configuration(),
        );

        $stored = self::$database?->query(
            'SELECT chatbot_publication_id, preview_draft_revision, preview_configuration_json, is_test
             FROM chatbot_sessions LIMIT 1',
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($stored);
        self::assertNull($stored['chatbot_publication_id']);
        self::assertSame('2', (string) $stored['preview_draft_revision']);
        self::assertSame('1', (string) $stored['is_test']);
        self::assertStringNotContainsString($token, (string) $stored['preview_configuration_json']);
    }

    public function testPublicationBoundSessionReservationCompletionAndCascadeDeletionPersist(): void
    {
        $connection = $this->connection();
        $chatbots = new PdoChatbotRepository($connection);
        $sourceId = $this->createReadySource();
        $chatbot = $chatbots->create('cb_conversation', 'Conversation', null, ChatbotFixtures::draft());
        $chatbot = $chatbots->replaceDraftAssignments(
            $chatbot->id,
            1,
            new ChatbotAssignments([$sourceId], ['https://example.com']),
        );
        $publication = $chatbots->publish(
            $chatbot->id,
            2,
            $chatbot->draft,
            $chatbot->assignments,
            ChatbotFixtures::provider(),
            str_repeat('d', 64),
        );
        $token = 'cst_v1_' . str_repeat('A', 43);
        $credentials = new ChatbotSessionCredentials(
            'cs_' . str_repeat('Z', 43),
            $token,
            substr($token, 0, 15),
            hash('sha256', $token),
        );
        $generator = new class($credentials) implements ChatbotSessionCredentialGeneratorInterface {
            public function __construct(private readonly ChatbotSessionCredentials $credentials)
            {
            }

            public function generate(): ChatbotSessionCredentials
            {
                return $this->credentials;
            }
        };
        $repository = new PdoChatbotConversationRepository($connection);
        $service = new ChatbotConversationService($repository, $generator);
        $created = $service->createSession(
            $chatbot->id,
            ChatbotSessionChannel::Browser,
            'https://example.com',
            false,
            new DateTimeImmutable('2026-07-20 10:00:00 UTC'),
        );

        self::assertSame($publication->id, $created->session->publicationId);
        $storedCredential = self::$database?->query(
            'SELECT public_id, token_prefix, token_hash FROM chatbot_sessions LIMIT 1',
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($storedCredential);
        self::assertSame(hash('sha256', $token), $storedCredential['token_hash']);
        self::assertFalse(in_array($token, $storedCredential, true));

        $reservation = $service->reserveMessage(
            $created->session->publicId,
            $token,
            'integration-turn-1',
            'Where is the handbook?',
            '018f9f3a-7420-7cc1-8a12-8ac550003333',
            new DateTimeImmutable('2026-07-20 10:01:00 UTC'),
        );
        self::assertSame(ChatbotMessageReservationState::Reserved, $reservation->state);

        $service->completeMessage(
            $reservation,
            new ChatbotMessageCompletion('Here it is.', 'openai', 'gpt-test-chat', 80, 10, 4, 2, 16),
            new DateTimeImmutable('2026-07-20 10:01:01 UTC'),
        );
        $replay = $service->reserveMessage(
            $created->session->publicId,
            $token,
            'integration-turn-1',
            'Where is the handbook?',
            '018f9f3a-7420-7cc1-8a12-8ac550003333',
            new DateTimeImmutable('2026-07-20 10:02:00 UTC'),
        );
        self::assertSame(ChatbotMessageReservationState::Replay, $replay->state);
        self::assertCount(2, $repository->messagesForSession($created->session->id));
        self::assertSame(1, $repository->findSessionByPublicId($created->session->publicId)?->messageCount);

        $repository->permanentlyDelete($created->session->id);
        self::assertSame(0, (int) self::$database?->query('SELECT COUNT(*) FROM chatbot_messages')->fetchColumn());
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$database?->exec('DELETE FROM chatbot_sessions');
        self::$database?->exec('UPDATE chatbots SET active_publication_id = NULL');
        self::$database?->exec('DELETE FROM chatbot_publications');
        self::$database?->exec('DELETE FROM chatbots');
        self::$database?->exec('UPDATE sources SET active_version_id = NULL');
        self::$database?->exec('DELETE FROM source_versions');
        self::$database?->exec('DELETE FROM sources');
    }

    private function connection(): Connection
    {
        $environment = TestDatabase::applicationEnvironment();

        return new Connection(new Config(['database' => [
            'host' => $environment['DB_HOST'],
            'port' => (int) $environment['DB_PORT'],
            'database' => $environment['DB_DATABASE'],
            'username' => $environment['DB_USERNAME'],
            'password' => $environment['DB_PASSWORD'],
            'charset' => 'utf8mb4',
        ]]));
    }

    private function createReadySource(): int
    {
        $statement = self::$database?->prepare(
            "INSERT INTO sources (name, source_type, status) VALUES ('Handbook', 'markdown', 'enabled')",
        );
        $statement?->execute();
        $sourceId = (int) self::$database?->lastInsertId();
        $version = self::$database?->prepare(
            "INSERT INTO source_versions
                (source_id, version_number, processing_status, content_hash, extracted_text, processed_at, activated_at)
             VALUES (:source_id, 1, 'ready', :content_hash, 'Ready content', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
        );
        $version?->execute(['source_id' => $sourceId, 'content_hash' => str_repeat('c', 64)]);
        $versionId = (int) self::$database?->lastInsertId();
        self::$database?->prepare('UPDATE sources SET active_version_id = :version_id WHERE id = :source_id')
            ->execute(['version_id' => $versionId, 'source_id' => $sourceId]);
        self::$database?->prepare(
            "INSERT INTO source_chunks
                (source_version_id, chunk_number, content, token_count, embedding, embedding_model,
                 embedding_dimensions, embedded_at, metadata_json)
             VALUES (:version_id, 1, 'Ready content', 2, '[0.1]', 'text-embedding-test', 1536, UTC_TIMESTAMP(6), '{}')",
        )->execute(['version_id' => $versionId]);

        return $sourceId;
    }
}
