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

    public function testEditShowsCopyableInstallationCodeForBothWidgetLayouts(): void
    {
        [$controller, $chatbots] = $this->controller(applicationUrl: 'https://askasio.example.com/');
        $chatbot = $chatbots->create('cb_embed', 'Support', null, ChatbotFixtures::draft());

        $response = $controller->edit($this->request(
            'GET',
            '/admin/chatbots/1/edit',
            routes: ['chatbotId' => (string) $chatbot->id],
        ));

        self::assertStringContainsString('Installation code', $response->body());
        self::assertStringContainsString('https://askasio.example.com/chat-widget/v1.js', $response->body());
        self::assertStringContainsString('data-chatbot-id=&quot;cb_embed&quot;', $response->body());
        self::assertStringContainsString('data-container-id=&quot;ask-asio-search&quot;', $response->body());
        self::assertStringContainsString('data-copy-target="floating-embed-code"', $response->body());
    }

    public function testEditUsesServerRoutedTaskTabs(): void
    {
        [$controller, $chatbots, $sources] = $this->controller();
        $chatbot = $chatbots->create('cb_tabs', 'Support', null, ChatbotFixtures::draft());
        $source = $sources->createSource('Support handbook', SourceType::Markdown);
        $chatbots->defineSource($source->id);

        $settings = $controller->edit($this->request(
            'GET',
            '/admin/chatbots/1/edit?tab=settings',
            query: ['tab' => 'settings'],
            routes: ['chatbotId' => (string) $chatbot->id],
        ));
        self::assertStringContainsString('href="/admin/chatbots/1/edit?tab=knowledge"', $settings->body());
        self::assertStringContainsString('aria-current="page"', $settings->body());
        self::assertStringContainsString('Save draft settings', $settings->body());
        self::assertStringNotContainsString('Source catalog', $settings->body());
        self::assertStringContainsString('Assign at least one knowledge source.', $settings->body());
        self::assertStringContainsString('Add at least one allowed browser origin.', $settings->body());

        $knowledge = $controller->edit($this->request(
            'GET',
            '/admin/chatbots/1/edit?tab=knowledge',
            query: ['tab' => 'knowledge'],
            routes: ['chatbotId' => (string) $chatbot->id],
        ));
        self::assertStringContainsString('Source catalog', $knowledge->body());
        self::assertStringContainsString('name="source_ids[]"', $knowledge->body());
        self::assertStringNotContainsString('Save draft settings', $knowledge->body());

        $access = $controller->edit($this->request(
            'GET',
            '/admin/chatbots/1/edit?tab=access',
            query: ['tab' => 'access'],
            routes: ['chatbotId' => (string) $chatbot->id],
        ));
        self::assertStringContainsString('Save allowed origins', $access->body());
        self::assertStringNotContainsString('Source catalog', $access->body());

        $lifecycle = $controller->edit($this->request(
            'GET',
            '/admin/chatbots/1/edit?tab=lifecycle',
            query: ['tab' => 'lifecycle'],
            routes: ['chatbotId' => (string) $chatbot->id],
        ));
        self::assertStringContainsString('Rotate public ID', $lifecycle->body());
        self::assertStringNotContainsString('Save allowed origins', $lifecycle->body());
    }

    public function testBatchSourceAssignmentsAreAtomicAndPreserveKnowledgeFilters(): void
    {
        [$controller, $chatbots, $sources] = $this->controller();
        $chatbot = $chatbots->create('cb_batch', 'Support', null, ChatbotFixtures::draft());
        $first = $sources->createSource('Handbook one', SourceType::Markdown);
        $second = $sources->createSource('Handbook two', SourceType::Markdown);
        $chatbots->defineSource($first->id);
        $chatbots->defineSource($second->id);

        $added = $controller->updateSources($this->request(
            'POST',
            '/admin/chatbots/1/sources?tab=knowledge&search=Handbook',
            query: ['tab' => 'knowledge', 'search' => 'Handbook'],
            body: [
                'revision' => '1',
                'assignment_action' => 'add',
                'source_ids' => [(string) $first->id, (string) $second->id],
            ],
            routes: ['chatbotId' => (string) $chatbot->id],
        ));

        self::assertSame(303, $added->status());
        self::assertSame('/admin/chatbots/1/edit?search=Handbook&tab=knowledge', $added->headers()['Location']);
        $afterAdd = $chatbots->findById(1);
        self::assertNotNull($afterAdd);
        self::assertSame([$first->id, $second->id], $afterAdd->assignments->sourceIds);
        self::assertSame(2, $afterAdd->draft->revision);

        $removed = $controller->updateSources($this->request(
            'POST',
            '/admin/chatbots/1/sources?tab=knowledge',
            query: ['tab' => 'knowledge'],
            body: [
                'revision' => '2',
                'assignment_action' => 'remove',
                'source_ids' => [(string) $first->id],
            ],
            routes: ['chatbotId' => (string) $chatbot->id],
        ));

        self::assertSame(303, $removed->status());
        $afterRemove = $chatbots->findById(1);
        self::assertNotNull($afterRemove);
        self::assertSame([$second->id], $afterRemove->assignments->sourceIds);
        self::assertSame(3, $afterRemove->draft->revision);
    }

    public function testInvalidEditTabAndEmptyBatchSelectionFailSafely(): void
    {
        [$controller, $chatbots] = $this->controller();
        $chatbot = $chatbots->create('cb_safe', 'Support', null, ChatbotFixtures::draft());
        $invalid = $controller->edit($this->request(
            'GET',
            '/admin/chatbots/1/edit?tab=secrets',
            query: ['tab' => 'secrets'],
            routes: ['chatbotId' => (string) $chatbot->id],
        ));
        self::assertSame('/admin/chatbots/1/edit?tab=settings', $invalid->headers()['Location']);

        $empty = $controller->updateSources($this->request(
            'POST',
            '/admin/chatbots/1/sources?tab=knowledge',
            query: ['tab' => 'knowledge'],
            body: ['revision' => '1', 'assignment_action' => 'add'],
            routes: ['chatbotId' => (string) $chatbot->id],
        ));
        self::assertSame('/admin/chatbots/1/edit?tab=knowledge', $empty->headers()['Location']);
        self::assertSame(1, $chatbots->findById(1)?->draft->revision);

        $followed = $controller->edit($this->request(
            'GET',
            '/admin/chatbots/1/edit?tab=knowledge',
            query: ['tab' => 'knowledge'],
            routes: ['chatbotId' => (string) $chatbot->id],
        ));
        self::assertStringContainsString('Select between 1 and 100 sources.', $followed->body());
    }

    /** @return array{ChatbotController, InMemoryChatbotRepository, InMemorySourceRepository} */
    private function controller(
        array $publicIds = ['cb_default'],
        bool $providerConfigured = true,
        string $applicationUrl = 'http://localhost:8080',
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
            applicationUrl: $applicationUrl,
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
            'layout' => 'floating',
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
