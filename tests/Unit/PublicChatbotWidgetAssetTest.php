<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PublicChatbotWidgetAssetTest extends TestCase
{
    private string $javascript;
    private string $css;

    protected function setUp(): void
    {
        $this->javascript = $this->asset('public/chat-widget/v1.js');
        $this->css = $this->asset('public/chat-widget/v1.css');
    }

    public function testLoaderIsVersionedIsolatedAndUsesSessionOnlyStorage(): void
    {
        self::assertStringContainsString('document.currentScript', $this->javascript);
        self::assertStringContainsString("attachShadow({ mode: 'open' })", $this->javascript);
        self::assertStringContainsString('askasio:chat:v1:', $this->javascript);
        self::assertStringContainsString('window.sessionStorage', $this->javascript);
        self::assertStringNotContainsString('localStorage', $this->javascript);
        self::assertStringNotContainsString('document.cookie', $this->javascript);
    }

    public function testLoaderImplementsThePublicApiAndBearerTokenContract(): void
    {
        self::assertStringContainsString("'/config'", $this->javascript);
        self::assertStringContainsString("'/sessions'", $this->javascript);
        self::assertStringContainsString("'/messages'", $this->javascript);
        self::assertStringContainsString("'/complete'", $this->javascript);
        self::assertStringContainsString("headers.Authorization = 'Bearer ' + options.token", $this->javascript);
        self::assertStringContainsString('pendingSubmission && pendingSubmission.message === message', $this->javascript);
        self::assertStringContainsString('idempotency_key: idempotencyKey', $this->javascript);
        self::assertStringContainsString("credentials: 'omit'", $this->javascript);
    }

    public function testRenderingAvoidsHtmlInjectionSinksAndUnsafeCitationSchemes(): void
    {
        self::assertStringContainsString('.textContent =', $this->javascript);
        self::assertStringContainsString("parsed.protocol === 'https:' || parsed.protocol === 'http:'", $this->javascript);
        self::assertStringNotContainsString('innerHTML', $this->javascript);
        self::assertStringNotContainsString('insertAdjacentHTML', $this->javascript);
        self::assertStringNotContainsString('eval(', $this->javascript);
        self::assertStringContainsString("rel = 'noopener noreferrer'", $this->javascript);
    }

    public function testAccessibleResponsiveInteractionHooksRemainPresent(): void
    {
        foreach ([
            'aria-expanded',
            'aria-controls',
            "role', 'dialog",
            "role', 'log",
            "role', 'group",
            "aria-label', 'Conversation",
            "aria-label', role === 'assistant' ? 'Assistant message' : 'Your message'",
            "aria-busy', next ? 'true' : 'false'",
            "event.key === 'Escape'",
            'inputLabel.htmlFor',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }

        self::assertStringContainsString('@media (max-width: 520px)', $this->css);
        self::assertStringContainsString('100dvh', $this->css);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $this->css);
        self::assertStringContainsString('@media (forced-colors: active)', $this->css);
        self::assertStringContainsString(':focus-visible', $this->css);
    }

    public function testInlineLayoutExpandsIntoABoundedFullscreenConversation(): void
    {
        foreach ([
            "appearance.layout === 'inline_fullscreen'",
            "script.dataset.containerId",
            "inline.setAttribute('role', 'search')",
            "panel.setAttribute('aria-modal', layout === 'inline_fullscreen' ? 'true' : 'false')",
            "event.key === 'Tab' && opened && layout === 'inline_fullscreen'",
            'lockPageScroll()',
            'moveInlineHostToPageOverlay()',
            'document.body.appendChild(host)',
            "host.style.setProperty('z-index', '2147483647', 'important')",
            'restoreInlineHost()',
            "inlinePromptPrefix.textContent = 'Try asking:'",
            'inlineInput.value = question',
            'inlineSuggestions = shuffleQuestions(normalizedQuestions)',
            'Math.floor(Math.random() * (index + 1))',
            'refreshInlinePrompt()',
            'inlineInput.addEventListener(\'focus\', pauseInlinePrompt)',
            "window.matchMedia('(prefers-reduced-motion: reduce)').matches",
            '}, 5000)',
        ] as $contract) {
            self::assertStringContainsString($contract, $this->javascript);
        }

        self::assertStringNotContainsString('Powered by Ask Asio', $this->javascript);
        self::assertStringNotContainsString('askasio-inline-title', $this->javascript);

        self::assertStringContainsString(':host([data-layout="inline_fullscreen"])', $this->css);
        self::assertStringContainsString('position: fixed;', $this->css);
        self::assertStringContainsString('height: 100dvh;', $this->css);
        self::assertStringContainsString('width: min(100%, 800px);', $this->css);
        self::assertStringContainsString('background: transparent;', $this->css);
        self::assertStringContainsString('.askasio-inline-prompt[hidden]', $this->css);
        self::assertStringContainsString("stylesheetUrl.search = new URL(script.src, document.baseURI).search", $this->javascript);
        self::assertStringContainsString('text-decoration: underline;', $this->css);
        self::assertStringContainsString('justify-content: center;', $this->css);
        self::assertStringContainsString('margin-right: 5px;', $this->css);
        self::assertStringContainsString('font-size: 15px;', $this->css);
        self::assertStringContainsString('font-family: inherit;', $this->css);
        self::assertStringNotContainsString('ui-sans-serif', $this->css);
    }

    private function asset(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($contents);

        return $contents;
    }
}
