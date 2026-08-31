<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Chatbots\ChatbotAssignments;
use App\Domain\Chatbots\ChatbotExecutionAudience;
use App\Domain\Chatbots\ChatbotMessage;
use App\Domain\Chatbots\ChatbotMessageRole;
use App\Domain\Chatbots\ChatbotMessageStatus;
use App\Domain\Chatbots\ChatbotProviderConfiguration;
use App\Domain\Chatbots\ChatbotSessionChannel;
use App\Domain\Chatbots\ChatbotSessionCredentials;
use App\Domain\RAG\RetrievedChunk;
use App\Exceptions\ChatbotExecutionException;
use App\Ingestion\Chunking\HeuristicTokenEstimator;
use App\Providers\Chat\ChatTimeoutException;
use App\RAG\AnswerGenerator;
use App\RAG\ChatExecutionErrorMapper;
use App\RAG\CitationProjector;
use App\RAG\ContextSelector;
use App\RAG\PromptBuilder;
use App\RAG\Retriever;
use App\Services\Chatbots\ChatbotConversationService;
use App\Services\Chatbots\ChatbotHistorySelector;
use App\Services\Chatbots\ChatbotSessionCredentialGeneratorInterface;
use App\Services\Chatbots\SharedChatExecutionService;
use App\Services\ProviderQuota\ProviderQuotaService;
use App\Services\ProviderQuota\ProviderUsageAccumulator;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeChatProvider;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Fakes\InMemoryChatbotConversationRepository;
use Tests\Fakes\InMemoryChatbotRepository;
use Tests\Fakes\InMemoryProviderQuotaRepository;
use Tests\Fakes\InMemoryVectorStore;
use Tests\Support\ChatbotFixtures;

final class SharedChatExecutionServiceTest extends TestCase
{
    public function testItEnforcesPublishedSourcesAndPersistsSafeCitationsUsageAndDiagnostics(): void
    {
        $chunk = new RetrievedChunk(
            18, 1, 3, 2, 'Refunds are available within 30 days.', 0.91,
            'Refund Policy', 'url', 'https://example.com/refunds', ['section_title' => 'Eligibility'],
        );
        $fixture = $this->fixture(
            [$chunk],
            sessionChannel: ChatbotSessionChannel::AdminPreview,
            draftPreview: true,
        );
        $reservation = $this->reserve($fixture, 'What is the refund policy?', 'turn-1', '018f9f3a-7420-7cc1-8a12-8ac550004444');
        $result = $fixture['executor']->execute(
            $reservation,
            ChatbotExecutionAudience::AdminPreview,
            'safe-test-user',
            new DateTimeImmutable('2026-07-20 10:01:01 UTC'),
        );

        self::assertSame([1], $fixture['vectors']->filters['source_ids']);
        self::assertNull($reservation->session->publicationId);
        self::assertSame(2, $reservation->session->previewDraftRevision);
        self::assertTrue($reservation->session->isTest);
        self::assertSame(0.2, $fixture['vectors']->filters['minimum_similarity']);
        self::assertSame('text-embedding-test', $fixture['vectors']->filters['embedding_model']);
        self::assertSame([
            'reference', 'title', 'heading', 'page', 'url',
        ], array_keys($result->citations[0]));
        self::assertArrayNotHasKey('chunk_id', $result->citations[0]);
        self::assertSame(18, $result->diagnostics['matches'][0]['chunk_id']);
        self::assertSame('fake-chat-model', $result->message->model);
        self::assertSame(160 + $result->usage['embedding_tokens'], $result->message->providerTokens);
        self::assertSame(ChatbotMessageStatus::Completed, $result->message->status);
        self::assertFalse($result->fallback);

        $stored = $fixture['conversations']->findSessionByPublicId($fixture['created']->session->publicId);
        self::assertSame($result->message->providerTokens, $stored?->providerTokens);
        self::assertSame(
            $result->message->providerTokens,
            $fixture['quotas']->snapshots([])['global']->dailyConsumed,
        );
    }

    public function testItIncludesOnlyRecentCompletedHistoryAndKeepsPublicDiagnosticsPrivate(): void
    {
        $chunk = new RetrievedChunk(
            1, 1, 1, 1, 'Policy context.', 0.9, 'Policy', 'markdown', null,
            ['canonical_url' => 'https://example.com/policy'],
        );
        $fixture = $this->fixture([$chunk]);
        $first = $this->reserve($fixture, 'First question', 'turn-1', '018f9f3a-7420-7cc1-8a12-8ac550005555');
        $fixture['executor']->execute(
            $first, ChatbotExecutionAudience::Public, 'visitor',
            new DateTimeImmutable('2026-07-20 10:01:01 UTC'),
        );
        $second = $this->reserve($fixture, 'Follow-up question', 'turn-2', '018f9f3a-7420-7cc1-8a12-8ac550006666');
        $result = $fixture['executor']->execute(
            $second, ChatbotExecutionAudience::Public, 'visitor',
            new DateTimeImmutable('2026-07-20 10:02:01 UTC'),
        );

        $request = $fixture['chat']->requests[1];
        $input = json_decode($request['input'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([
            ['role' => 'user', 'content' => 'First question'],
            ['role' => 'assistant', 'content' => 'Grounded answer [S1].'],
        ], $input['conversation_history']);
        self::assertSame('Follow-up question', $input['question']);
        self::assertStringContainsString('Additional administrator-authored behavior instructions', $request['instructions']);
        self::assertSame('https://example.com/policy', $result->citations[0]['url']);
        self::assertSame([], $result->diagnostics);
    }

    public function testPublicAudienceCannotExecuteAdministratorTestTraffic(): void
    {
        $fixture = $this->fixture(
            [new RetrievedChunk(1, 1, 1, 1, 'Private diagnostic context.', 0.9, 'Policy', 'markdown', null, [])],
            sessionChannel: ChatbotSessionChannel::AdminPreview,
            draftPreview: true,
        );
        $reservation = $this->reserve(
            $fixture,
            'Question',
            'turn-audience',
            '018f9f3a-7420-7cc1-8a12-8ac550001111',
        );

        try {
            $fixture['executor']->execute(
                $reservation,
                ChatbotExecutionAudience::Public,
                'visitor',
                new DateTimeImmutable('2026-07-20 10:01:01 UTC'),
            );
            self::fail('Administrator test traffic must not execute as public traffic.');
        } catch (ChatbotExecutionException $exception) {
            self::assertSame(403, $exception->statusCode);
            self::assertSame('execution_audience_mismatch', $exception->errorCode);
        }

        self::assertSame([], $fixture['embedding']->inputs);
        self::assertSame([], $fixture['chat']->requests);
        self::assertSame(0, $fixture['quotaRepository']->reservationAttempts);
    }

    public function testNoEvidenceUsesConfiguredFallbackWithoutCallingChatGeneration(): void
    {
        $fixture = $this->fixture([]);
        $reservation = $this->reserve($fixture, 'Unknown topic', 'turn-1', '018f9f3a-7420-7cc1-8a12-8ac550007777');
        $result = $fixture['executor']->execute(
            $reservation, ChatbotExecutionAudience::Public, 'visitor',
            new DateTimeImmutable('2026-07-20 10:01:01 UTC'),
        );

        self::assertTrue($result->fallback);
        self::assertSame('I could not find enough information to answer that question.', $result->answer);
        self::assertSame([], $result->citations);
        self::assertSame([], $fixture['chat']->requests);
        self::assertSame(0, $result->message->inputTokens);
        self::assertGreaterThan(0, $result->message->embeddingTokens);
    }

    public function testDisabledPublicCitationDisplayKeepsGroundingButOmitsMarkersAndProjection(): void
    {
        $chunk = new RetrievedChunk(1, 1, 1, 1, 'Policy context.', 0.9, 'Policy', 'url', 'https://example.com/policy', []);
        $fixture = $this->fixture([$chunk], citationsEnabled: false);
        $reservation = $this->reserve($fixture, 'Question', 'turn-1', '018f9f3a-7420-7cc1-8a12-8ac550000111');
        $result = $fixture['executor']->execute(
            $reservation, ChatbotExecutionAudience::Public, 'visitor',
            new DateTimeImmutable('2026-07-20 10:01:01 UTC'),
        );

        self::assertSame('Grounded answer.', $result->answer);
        self::assertSame([], $result->citations);
        self::assertStringContainsString('using exactly [S1]', $fixture['chat']->requests[0]['instructions']);
    }

    public function testProviderFailureIsSafelyMappedPersistedAndConservativelyReconciled(): void
    {
        $chunk = new RetrievedChunk(1, 1, 1, 1, 'Context.', 0.9, 'Policy', 'markdown', null, []);
        $fixture = $this->fixture([$chunk], new FakeChatProvider(failure: new ChatTimeoutException('secret timeout')));
        $reservation = $this->reserve($fixture, 'Question', 'turn-1', '018f9f3a-7420-7cc1-8a12-8ac550008888');

        try {
            $fixture['executor']->execute(
                $reservation, ChatbotExecutionAudience::Public, 'visitor',
                new DateTimeImmutable('2026-07-20 10:01:01 UTC'),
            );
            self::fail('A provider timeout must fail safely.');
        } catch (ChatbotExecutionException $exception) {
            self::assertSame(504, $exception->statusCode);
            self::assertSame('provider_timeout', $exception->errorCode);
            self::assertStringNotContainsString('secret timeout', $exception->getMessage());
        }

        $messages = $fixture['conversations']->messagesForSession($fixture['created']->session->id);
        self::assertSame(ChatbotMessageStatus::Failed, $messages[1]->status);
        self::assertSame('provider_timeout', $messages[1]->errorCode);
        self::assertGreaterThan(0, $messages[1]->providerTokens);
        self::assertSame(
            $messages[1]->providerTokens,
            $fixture['quotas']->snapshots([])['global']->dailyConsumed,
        );
    }

    public function testStalePublicationConfigurationFailsBeforeProviderOrQuotaAccess(): void
    {
        $fixture = $this->fixture(
            [new RetrievedChunk(1, 1, 1, 1, 'Context.', 0.9, 'Policy', 'markdown', null, [])],
            installationProvider: new ChatbotProviderConfiguration('openai', 'changed-model', 'openai', 'text-embedding-test', 1536),
        );
        $reservation = $this->reserve($fixture, 'Question', 'turn-1', '018f9f3a-7420-7cc1-8a12-8ac550009999');

        try {
            $fixture['executor']->execute(
                $reservation, ChatbotExecutionAudience::Public, 'visitor',
                new DateTimeImmutable('2026-07-20 10:01:01 UTC'),
            );
            self::fail('A stale publication must be rejected.');
        } catch (ChatbotExecutionException $exception) {
            self::assertSame('publication_configuration_stale', $exception->errorCode);
        }

        self::assertSame([], $fixture['embedding']->inputs);
        self::assertSame([], $fixture['chat']->requests);
        self::assertSame(0, $fixture['quotaRepository']->reservationAttempts);
    }

    public function testHistoryBudgetDropsOldestTurnsAndExcludesFailedOutcomes(): void
    {
        $selector = new ChatbotHistorySelector(new HeuristicTokenEstimator(), 40);
        $messages = [
            $this->historyMessage(1, ChatbotMessageRole::User, ChatbotMessageStatus::Completed, str_repeat('A', 40)),
            $this->historyMessage(2, ChatbotMessageRole::Assistant, ChatbotMessageStatus::Completed, str_repeat('B', 40), 1),
            $this->historyMessage(3, ChatbotMessageRole::User, ChatbotMessageStatus::Completed, str_repeat('C', 40)),
            $this->historyMessage(4, ChatbotMessageRole::Assistant, ChatbotMessageStatus::Completed, str_repeat('D', 40), 3),
            $this->historyMessage(5, ChatbotMessageRole::User, ChatbotMessageStatus::Completed, 'Failed question'),
            $this->historyMessage(6, ChatbotMessageRole::Assistant, ChatbotMessageStatus::Failed, null, 5),
            $this->historyMessage(7, ChatbotMessageRole::User, ChatbotMessageStatus::Pending, 'Current question'),
        ];

        $selected = $selector->select($messages, 7);

        self::assertSame([
            ['role' => 'user', 'content' => str_repeat('C', 40)],
            ['role' => 'assistant', 'content' => str_repeat('D', 40)],
        ], $selected->messages);
        self::assertLessThanOrEqual(40, $selected->estimatedTokens);
        self::assertSame('recent_completed_turns_v1', $selected->policy);
    }

    /**
     * @param list<RetrievedChunk> $matches
     * @return array{
     *   executor: SharedChatExecutionService,
     *   conversationService: ChatbotConversationService,
     *   conversations: InMemoryChatbotConversationRepository,
     *   credentials: ChatbotSessionCredentials,
     *   created: \App\Domain\Chatbots\CreatedChatbotSession,
     *   vectors: InMemoryVectorStore,
     *   embedding: FakeEmbeddingProvider,
     *   chat: FakeChatProvider,
     *   quotas: ProviderQuotaService,
     *   quotaRepository: InMemoryProviderQuotaRepository
     *   chatbots: InMemoryChatbotRepository
     * }
     */
    private function fixture(
        array $matches,
        ?FakeChatProvider $chat = null,
        ?ChatbotProviderConfiguration $installationProvider = null,
        ChatbotSessionChannel $sessionChannel = ChatbotSessionChannel::Browser,
        bool $citationsEnabled = true,
        bool $draftPreview = false,
    ): array {
        $chatbots = new InMemoryChatbotRepository();
        $chatbots->defineSource(1);
        $chatbot = $chatbots->create(
            'cb_shared',
            'Shared',
            null,
            ChatbotFixtures::draft(citationsEnabled: $citationsEnabled),
        );
        $chatbot = $chatbots->replaceDraftAssignments(
            $chatbot->id,
            1,
            new ChatbotAssignments([1], ['https://example.com']),
        );
        $publication = $chatbots->publish(
            $chatbot->id,
            2,
            $chatbot->draft,
            $chatbot->assignments,
            ChatbotFixtures::provider(),
            str_repeat('e', 64),
        );
        $conversations = new InMemoryChatbotConversationRepository();
        $conversations->defineChatbot($chatbot->id, publicationId: $publication->id, maximumMessages: 40);
        $token = 'cst_v1_' . str_repeat('A', 43);
        $credentials = new ChatbotSessionCredentials(
            'cs_' . str_repeat('Z', 43), $token, substr($token, 0, 15), hash('sha256', $token),
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
        $conversationService = new ChatbotConversationService($conversations, $generator);
        $created = $draftPreview
            ? $conversationService->createDraftPreviewSession(
                $chatbot,
                ChatbotFixtures::provider(),
                new DateTimeImmutable('2026-07-20 10:00:00 UTC'),
            )
            : $conversationService->createSession(
                $chatbot->id,
                $sessionChannel,
                $sessionChannel === ChatbotSessionChannel::Browser ? 'https://example.com' : null,
                $sessionChannel === ChatbotSessionChannel::AdminPreview,
                new DateTimeImmutable('2026-07-20 10:00:00 UTC'),
            );
        $vectors = new InMemoryVectorStore($matches);
        $embedding = new FakeEmbeddingProvider(modelName: 'text-embedding-test');
        $chat ??= new FakeChatProvider();
        $usage = new ProviderUsageAccumulator();
        $quotaRepository = new InMemoryProviderQuotaRepository();
        $quotas = new ProviderQuotaService($quotaRepository, 1_000_000, 10_000_000, 0, 0, 900);
        $tokens = new HeuristicTokenEstimator();
        $answers = new AnswerGenerator(
            new Retriever($embedding, $vectors, 5, 8, 0.2),
            new ContextSelector($tokens, 4_000),
            new PromptBuilder(),
            $chat,
        );
        $executor = new SharedChatExecutionService(
            $chatbots,
            $conversations,
            $answers,
            new ChatbotHistorySelector($tokens, 1_000),
            new CitationProjector(),
            new ChatExecutionErrorMapper(),
            $quotas,
            $usage,
            $installationProvider ?? ChatbotFixtures::provider(),
            $tokens,
            4_000,
            600,
        );

        return compact(
            'executor', 'conversationService', 'conversations', 'credentials', 'created',
            'vectors', 'embedding', 'chat', 'quotas', 'quotaRepository', 'chatbots',
        );
    }

    /** @param array<string, mixed> $fixture */
    private function reserve(
        array $fixture,
        string $question,
        string $key,
        string $requestId,
    ): \App\Domain\Chatbots\ChatbotMessageReservation {
        /** @var ChatbotConversationService $service */
        $service = $fixture['conversationService'];
        /** @var \App\Domain\Chatbots\CreatedChatbotSession $created */
        $created = $fixture['created'];
        /** @var ChatbotSessionCredentials $credentials */
        $credentials = $fixture['credentials'];

        return $service->reserveMessage(
            $created->session->publicId,
            $credentials->token,
            $key,
            $question,
            $requestId,
            new DateTimeImmutable('2026-07-20 10:01:00 UTC'),
        );
    }

    private function historyMessage(
        int $id,
        ChatbotMessageRole $role,
        ChatbotMessageStatus $status,
        ?string $content,
        ?int $replyTo = null,
    ): ChatbotMessage {
        return new ChatbotMessage(
            $id,
            1,
            $replyTo,
            $role,
            $status,
            $content,
            $content === null ? null : hash('sha256', $content),
            $role === ChatbotMessageRole::User ? hash('sha256', 'key-' . $id) : null,
            sprintf('018f9f3a-7420-7cc1-8a12-%012d', $id),
            null,
            null,
            null,
            0,
            0,
            0,
            0,
            null,
            null,
            $status === ChatbotMessageStatus::Failed ? 'provider_error' : null,
            '2026-07-20 10:00:00.000000',
            $status === ChatbotMessageStatus::Pending ? null : '2026-07-20 10:00:01.000000',
        );
    }
}
