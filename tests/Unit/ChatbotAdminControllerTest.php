<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Admin\ChatbotController;
use App\Domain\Admin\AdminUser;
use App\Domain\Chatbots\ChatbotProviderConfiguration;
use App\Domain\Sources\SourceType;
use App\Http\Request;
use App\Security\CsrfTokenManager;
use App\Services\Chatbots\ChatbotAdminFormParser;
use App\Services\Chatbots\ChatbotDraftDefaults;
use App\Services\Chatbots\ChatbotDraftValidator;
use App\Services\Chatbots\ChatbotListQueryParser;
use App\Services\Chatbots\ChatbotPublicIdGeneratorInterface;
use App\Services\Chatbots\ChatbotService;
use App\Services\Sources\SourceListQueryParser;
use App\Support\ViewRenderer;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryChatbotRepository;
use Tests\Fakes\InMemorySessionStore;
use Tests\Fakes\InMemorySourceRepository;
use Tests\Support\ChatbotFixtures;

final class ChatbotAdminControllerTest extends TestCase
{
    public function testListIsPaginatedFilterableAndUsesSafeEmptyStates(): void
    {
        [$controller, $chatbots] = $this->controller();

        for ($index = 1; $index <= 30; ++$index) {
            $chatbots->create('cb_' . $index, sprintf('Support %02d', $index), null, ChatbotFixtures::draft());
        }

        $response = $controller->index($this->request(
            'GET',
            '/admin/chatbots?page=2&search=Support',
            query: ['page' => '2', 'search' => 'Support'],
        ));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('Showing <strong>26–30</strong> of <strong>30</strong> chatbots', $response->body());
        self::assertStringContainsString('search=Support', $response->body());
        self::assertStringContainsString('Assigned source', $response->body());
        self::assertStringContainsString('All models', $response->body());

        $empty = $controller->index($this->request(
            'GET',
            '/admin/chatbots?search=missing',
            query: ['search' => 'missing'],
        ));
        self::assertStringContainsString('No chatbots match these filters', $empty->body());
    }

    public function testInvalidListInputRedirectsAndEscapesPersistedFormInput(): void
    {
        [$controller] = $this->controller();
        $invalid = $controller->index($this->request(
            'GET',
            '/admin/chatbots',
            query: ['sort' => 'system_instructions'],
        ));
        self::assertSame('/admin/chatbots', $invalid->headers()['Location']);

        $followed = $controller->index($this->request('GET', '/admin/chatbots'));
        self::assertStringContainsString('The sort parameter is invalid.', $followed->body());
        self::assertStringNotContainsString('system_instructions', $followed->body());

        $create = $controller->store($this->request('POST', '/admin/chatbots', body: [
            'name' => '<script>alert(1)</script>',
            'description' => 'Example',
        ]));
        self::assertSame(422, $create->status());
        self::assertStringNotContainsString('<script>', $create->body());
        self::assertStringContainsString('&lt;script&gt;', $create->body());
    }

    public function testCreateEditAssignmentsPublicationAndLifecycleActions(): void
    {
        [$controller, $chatbots, $sources] = $this->controller(['cb_created', 'cb_rotated']);
        $source = $sources->createSource('Ready handbook', SourceType::Markdown);
        $chatbots->defineSource($source->id);
        $created = $controller->store($this->request('POST', '/admin/chatbots', body: [
            'name' => 'Website support',
            'description' => 'Customer answers',
        ]));
        self::assertSame('/admin/chatbots/1/edit', $created->headers()['Location']);

        $chatbot = $chatbots->findById(1);
        self::assertNotNull($chatbot);
        $updated = $controller->update($this->request(
            'POST',
            '/admin/chatbots/1',
            body: $this->draftBody(1, '<b>unsafe</b>'),
            routes: ['chatbotId' => '1'],
        ));
        self::assertSame(422, $updated->status());
        self::assertStringContainsString('must not contain HTML', $updated->body());

        $assign = $controller->assignSource($this->request(
            'POST',
            '/admin/chatbots/1/sources/1/assign',
            body: ['revision' => '1'],
            routes: ['chatbotId' => '1', 'sourceId' => (string) $source->id],
        ));
        self::assertSame(303, $assign->status());
        self::assertSame([$source->id], $chatbots->findById(1)?->assignments->sourceIds);
        self::assertSame(2, $chatbots->findById(1)?->draft->revision);

        $origins = $controller->updateOrigins($this->request(
            'POST',
            '/admin/chatbots/1/origins',
            body: ['revision' => '2', 'origins' => "https://EXAMPLE.com:443/\n"],
            routes: ['chatbotId' => '1'],
        ));
        self::assertSame(303, $origins->status());
        self::assertSame(['https://example.com'], $chatbots->findById(1)?->assignments->origins);

        $publish = $controller->publish($this->request(
            'POST',
            '/admin/chatbots/1/publish',
            routes: ['chatbotId' => '1'],
        ));
        self::assertSame(303, $publish->status());
        self::assertNotNull($chatbots->findById(1)?->activePublicationId);

        $controller->disable($this->request('POST', '/admin/chatbots/1/disable', routes: ['chatbotId' => '1']));
        self::assertSame('disabled', $chatbots->findById(1)?->status->value);
        $controller->enable($this->request('POST', '/admin/chatbots/1/enable', routes: ['chatbotId' => '1']));
        self::assertSame('active', $chatbots->findById(1)?->status->value);
        $controller->rotatePublicId($this->request('POST', '/admin/chatbots/1/rotate-public-id', routes: ['chatbotId' => '1']));
        self::assertSame('cb_rotated', $chatbots->findById(1)?->publicId);
        $controller->archive($this->request('POST', '/admin/chatbots/1/archive', routes: ['chatbotId' => '1']));

        $incorrect = $controller->permanentlyDelete($this->request(
            'POST',
            '/admin/chatbots/1/permanent-delete',
            body: ['confirmation' => 'website support'],
            routes: ['chatbotId' => '1'],
        ));
        self::assertSame(303, $incorrect->status());
        self::assertNotNull($chatbots->findById(1));

        $controller->permanentlyDelete($this->request(
            'POST',
            '/admin/chatbots/1/permanent-delete',
            body: ['confirmation' => 'Website support'],
            routes: ['chatbotId' => '1'],
        ));
        self::assertNull($chatbots->findById(1));
    }

    public function testProviderConfigurationFailureKeepsDraftUiAvailableAndBlocksPublication(): void
    {
        [$controller, $chatbots] = $this->controller(providerConfigured: false);
        $chatbot = $chatbots->create('cb_unconfigured', 'Support', null, ChatbotFixtures::draft());
        $view = $controller->edit($this->request(
            'GET',
            '/admin/chatbots/1/edit',
            routes: ['chatbotId' => (string) $chatbot->id],
        ));
        self::assertSame(200, $view->status());
        self::assertStringContainsString('Draft editing remains available', $view->body());
        self::assertStringContainsString('Not configured', $view->body());

        $controller->publish($this->request(
            'POST',
            '/admin/chatbots/1/publish',
            routes: ['chatbotId' => (string) $chatbot->id],
        ));
        $followed = $controller->edit($this->request(
            'GET',
            '/admin/chatbots/1/edit',
            routes: ['chatbotId' => (string) $chatbot->id],
        ));
        self::assertStringContainsString('Configure the installation chat and embedding models', $followed->body());
    }

    /** @return array{ChatbotController, InMemoryChatbotRepository, InMemorySourceRepository} */
    private function controller(
        array $publicIds = ['cb_default'],
        bool $providerConfigured = true,
    ): array {
        $chatbots = new InMemoryChatbotRepository();
        $sources = new InMemorySourceRepository();
        $session = new InMemorySessionStore();
        $provider = new ChatbotProviderConfiguration(
            $providerConfigured ? 'openai' : 'unconfigured',
            $providerConfigured ? 'gpt-test-chat' : 'unconfigured',
            $providerConfigured ? 'openai' : 'unconfigured',
            $providerConfigured ? 'text-embedding-test' : 'unconfigured',
            $providerConfigured ? 1536 : null,
        );
        $generator = new class($publicIds) implements ChatbotPublicIdGeneratorInterface {
            public function __construct(private array $ids)
            {
            }

            public function generate(): string
            {
                return array_shift($this->ids) ?? throw new \RuntimeException('No public ID remains.');
            }
        };
        $service = new ChatbotService(
            $chatbots,
            new ChatbotDraftValidator(8, 4_000),
            $generator,
            $provider,
            providerAvailable: $providerConfigured,
        );
        $controller = new ChatbotController(
            $chatbots,
            $sources,
            $service,
            new ChatbotDraftDefaults(5, 0.2, 4_000),
            new ChatbotAdminFormParser(),
            new ChatbotListQueryParser('UTC'),
            new SourceListQueryParser('UTC'),
            $provider,
            new ViewRenderer(dirname(__DIR__, 2) . '/resources/views'),
            new CsrfTokenManager($session),
            $session,
            'testing',
            $providerConfigured,
            8,
            4_000,
        );

        return [$controller, $chatbots, $sources];
    }

    /** @return array<string, string> */
    private function draftBody(int $revision, string $displayName): array
    {
        return [
            'revision' => (string) $revision,
            'name' => 'Website support',
            'description' => 'Customer answers',
            'system_instructions' => 'Answer only from sources.',
            'fallback_message' => 'No supported answer.',
            'retrieval_top_k' => '5',
            'minimum_similarity' => '0.2',
            'citations_enabled' => '1',
            'maximum_message_characters' => '4000',
            'maximum_messages_per_session' => '40',
            'idle_expiry_minutes' => '30',
            'absolute_expiry_minutes' => '480',
            'retention_days' => '30',
            'privacy_notice_url' => 'https://example.com/privacy',
            'disclosure_text' => 'Automated assistant.',
            'display_name' => $displayName,
            'welcome_message' => 'How can I help?',
            'input_placeholder' => 'Ask a question',
            'suggested_questions' => "Refunds?\nShipping?",
            'accent' => '#0B7BDD',
            'theme' => 'light',
            'position' => 'right',
            'launcher_label' => 'Chat',
            'launcher_icon' => 'chat',
            'panel_title' => 'Support',
            'size' => 'standard',
        ];
    }

    /** @param array<string, mixed> $query @param array<string, mixed> $body @param array<string, string> $routes */
    private function request(
        string $method,
        string $uri,
        array $query = [],
        array $body = [],
        array $routes = [],
    ): Request {
        return new Request(
            $method,
            $uri,
            query: $query,
            parsedBody: $body,
            attributes: [
                'admin_user' => new AdminUser(1, 'asio', 'unused'),
                'route_parameters' => $routes,
            ],
        );
    }
}
