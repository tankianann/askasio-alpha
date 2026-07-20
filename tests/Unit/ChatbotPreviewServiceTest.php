<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\ChatbotController;
use App\Domain\Admin\AdminUser;
use App\Domain\Chatbots\ChatbotAssignments;
use App\Domain\Chatbots\ChatbotSessionCredentials;
use App\Domain\RAG\RetrievedChunk;
use App\Http\Request;
use App\Ingestion\Chunking\HeuristicTokenEstimator;
use App\RAG\AnswerGenerator;
use App\RAG\ChatExecutionErrorMapper;
use App\RAG\CitationProjector;
use App\RAG\ContextSelector;
use App\RAG\PromptBuilder;
use App\RAG\Retriever;
use App\Security\CsrfTokenManager;
use App\Services\Chatbots\ChatbotAdminFormParser;
use App\Services\Chatbots\ChatbotConversationService;
use App\Services\Chatbots\ChatbotDraftDefaults;
use App\Services\Chatbots\ChatbotDraftValidator;
use App\Services\Chatbots\ChatbotHistorySelector;
use App\Services\Chatbots\ChatbotListQueryParser;
use App\Services\Chatbots\ChatbotPreviewService;
use App\Services\Chatbots\ChatbotPublicIdGeneratorInterface;
use App\Services\Chatbots\ChatbotService;
use App\Services\Chatbots\ChatbotSessionCredentialGeneratorInterface;
use App\Services\Chatbots\SharedChatExecutionService;
use App\Services\ProviderQuota\ProviderQuotaService;
use App\Services\ProviderQuota\ProviderUsageAccumulator;
use App\Services\Sources\SourceListQueryParser;
use App\Support\ViewRenderer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\FakeChatProvider;
use Tests\Fakes\FakeEmbeddingProvider;
use Tests\Fakes\InMemoryChatbotConversationRepository;
use Tests\Fakes\InMemoryChatbotRepository;
use Tests\Fakes\InMemoryProviderQuotaRepository;
use Tests\Fakes\InMemorySessionStore;
use Tests\Fakes\InMemorySourceRepository;
use Tests\Fakes\InMemoryVectorStore;
use Tests\Support\ChatbotFixtures;

final class ChatbotPreviewServiceTest extends TestCase
{
    public function testDraftPreviewCreatesSeparateTestTrafficAndRestartsAfterDraftChanges(): void
    {
        $fixture = $this->fixture();
        $first = $fixture['preview']->send(
            $fixture['chatbot'], 'First question', 'preview-1',
            '018f9f3a-7420-7cc1-8a12-8ac550001234', 'admin-test',
            new DateTimeImmutable('2026-07-20 10:00:00 UTC'),
        );
        $firstState = $fixture['preview']->current($fixture['chatbot']);

        self::assertNotNull($firstState);
        self::assertTrue($firstState->session->isTest);
        self::assertNull($firstState->session->publicationId);
        self::assertSame(2, $firstState->session->previewDraftRevision);
        self::assertCount(2, $firstState->messages);
        self::assertSame(1, $first->diagnostics['matches'][0]['source_id']);

        $updated = $fixture['chatbots']->updateDraft(
            $fixture['chatbot']->id,
            2,
            'Preview chatbot',
            null,
            ChatbotFixtures::draft(displayName: 'Updated preview'),
        );
        $fixture['preview']->send(
            $updated, 'Fresh revision question', 'preview-2',
            '018f9f3a-7420-7cc1-8a12-8ac550005678', 'admin-test',
            new DateTimeImmutable('2026-07-20 10:05:00 UTC'),
        );
        $secondState = $fixture['preview']->current($updated);

        self::assertNotNull($secondState);
        self::assertNotSame($firstState->session->id, $secondState->session->id);
        self::assertSame(3, $secondState->session->previewDraftRevision);
        self::assertCount(2, $secondState->messages);
    }

    public function testAdminPreviewPageEscapesTranscriptAndShowsBoundedDiagnostics(): void
    {
        $fixture = $this->fixture();
        $controller = $this->controller($fixture);
        $send = $controller->sendPreviewMessage($this->request('POST', '/admin/chatbots/1/preview/messages', [
            'message' => '<script>alert(1)</script> What is covered?',
            'idempotency_key' => 'preview-form-1',
            'request_id' => '018f9f3a-7420-7cc1-8a12-8ac550009876',
        ]));
        self::assertSame(303, $send->status());

        $page = $controller->preview($this->request('GET', '/admin/chatbots/1/preview'));
        self::assertSame(200, $page->status());
        self::assertStringContainsString('Test only', $page->body());
        self::assertStringContainsString('Grounded answer [S1].', $page->body());
        self::assertStringContainsString('Latest diagnostics', $page->body());
        self::assertStringContainsString('source 1', $page->body());
        self::assertStringNotContainsString('<script>alert(1)</script>', $page->body());
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page->body());
    }

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $chatbots = new InMemoryChatbotRepository();
        $chatbots->defineSource(1);
        $chatbot = $chatbots->create('cb_preview', 'Preview chatbot', null, ChatbotFixtures::draft());
        $chatbot = $chatbots->replaceDraftAssignments(
            $chatbot->id, 1, new ChatbotAssignments([1], ['https://example.com']),
        );
        $conversations = new InMemoryChatbotConversationRepository();
        $conversations->defineChatbot($chatbot->id);
        $tokens = [
            $this->credentials('A', 'Z'),
            $this->credentials('B', 'Y'),
            $this->credentials('C', 'X'),
        ];
        $credentialGenerator = new class($tokens) implements ChatbotSessionCredentialGeneratorInterface {
            /** @param list<ChatbotSessionCredentials> $tokens */
            public function __construct(private array $tokens)
            {
            }

            public function generate(): ChatbotSessionCredentials
            {
                return array_shift($this->tokens) ?? throw new \RuntimeException('No preview credential remains.');
            }
        };
        $conversationService = new ChatbotConversationService($conversations, $credentialGenerator);
        $session = new InMemorySessionStore();
        $estimator = new HeuristicTokenEstimator();
        $provider = ChatbotFixtures::provider();
        $executor = new SharedChatExecutionService(
            $chatbots,
            $conversations,
            new AnswerGenerator(
                new Retriever(
                    new FakeEmbeddingProvider(modelName: 'text-embedding-test'),
                    new InMemoryVectorStore([
                        new RetrievedChunk(1, 1, 1, 1, 'Coverage lasts 30 days.', 0.9, 'Policy', 'url', 'https://example.com/policy', []),
                    ]),
                    5, 8, 0.2,
                ),
                new ContextSelector($estimator, 4_000),
                new PromptBuilder(),
                new FakeChatProvider(),
            ),
            new ChatbotHistorySelector($estimator, 1_000),
            new CitationProjector(),
            new ChatExecutionErrorMapper(),
            new ProviderQuotaService(new InMemoryProviderQuotaRepository(), 1_000_000, 10_000_000, 0, 0, 900),
            new ProviderUsageAccumulator(),
            $provider,
            $estimator,
            4_000,
            600,
        );
        $preview = new ChatbotPreviewService($conversationService, $executor, $session, $provider);

        return compact('chatbots', 'chatbot', 'conversations', 'conversationService', 'session', 'provider', 'preview');
    }

    /** @param array<string, mixed> $fixture */
    private function controller(array $fixture): ChatbotController
    {
        /** @var InMemoryChatbotRepository $chatbots */
        $chatbots = $fixture['chatbots'];
        /** @var InMemorySessionStore $session */
        $session = $fixture['session'];
        $publicIds = new class implements ChatbotPublicIdGeneratorInterface {
            public function generate(): string
            {
                return 'cb_unused';
            }
        };

        return new ChatbotController(
            $chatbots,
            new InMemorySourceRepository(),
            new ChatbotService($chatbots, new ChatbotDraftValidator(8, 4_000), $publicIds, $fixture['provider']),
            new ChatbotDraftDefaults(5, 0.2, 4_000),
            new ChatbotAdminFormParser(),
            new ChatbotListQueryParser('UTC'),
            new SourceListQueryParser('UTC'),
            $fixture['provider'],
            new ViewRenderer(dirname(__DIR__, 2) . '/resources/views'),
            new CsrfTokenManager($session),
            $session,
            'testing',
            true,
            8,
            4_000,
            $fixture['preview'],
            str_repeat('s', 32),
        );
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, string $uri, array $body = []): Request
    {
        return new Request($method, $uri, parsedBody: $body, attributes: [
            'admin_user' => new AdminUser(1, 'asio', 'unused'),
            'route_parameters' => ['chatbotId' => '1'],
        ]);
    }

    private function credentials(string $tokenCharacter, string $idCharacter): ChatbotSessionCredentials
    {
        $token = 'cst_v1_' . str_repeat($tokenCharacter, 43);

        return new ChatbotSessionCredentials(
            'cs_' . str_repeat($idCharacter, 43),
            $token,
            substr($token, 0, 15),
            hash('sha256', $token),
        );
    }
}
