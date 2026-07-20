<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Chatbots\ChatbotDraft;
use App\Exceptions\ValidationException;
use App\Services\Chatbots\ChatbotDraftValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ChatbotFixtures;

final class ChatbotDraftValidatorTest extends TestCase
{
    public function testItNormalizesBoundedSettings(): void
    {
        $draft = ChatbotFixtures::draft(displayName: '  Support assistant  ');
        $normalized = $this->validator()->validateAndNormalize($draft);

        self::assertSame('Support assistant', $normalized->presentation['display_name']);
        self::assertSame('#2457D6', $normalized->appearance['accent']);
        self::assertSame(30, $normalized->retentionDays);
        self::assertSame('https://example.com/privacy', $normalized->privacyNoticeUrl);
    }

    #[DataProvider('invalidDrafts')]
    public function testItRejectsUnsafeOrOutOfRangeSettings(ChatbotDraft $draft): void
    {
        $this->expectException(ValidationException::class);
        $this->validator()->validateAndNormalize($draft);
    }

    /** @return iterable<string, array{ChatbotDraft}> */
    public static function invalidDrafts(): iterable
    {
        $valid = ChatbotFixtures::draft();

        yield 'top k' => [self::copy($valid, retrievalTopK: 9)];
        yield 'similarity' => [self::copy($valid, minimumSimilarity: -0.1)];
        yield 'retention' => [self::copy($valid, retentionDays: 14)];
        yield 'expiry' => [self::copy($valid, idleExpiryMinutes: 60, absoluteExpiryMinutes: 30)];
        yield 'insecure privacy URL' => [self::copy($valid, privacyNoticeUrl: 'http://example.com/privacy')];
        yield 'presentation HTML' => [self::copy($valid, presentation: [
            ...$valid->presentation,
            'welcome_message' => '<script>alert(1)</script>',
        ])];
        yield 'unknown presentation field' => [self::copy($valid, presentation: [
            ...$valid->presentation,
            'custom_javascript' => 'alert(1)',
        ])];
    }

    private function validator(): ChatbotDraftValidator
    {
        return new ChatbotDraftValidator(8, 4_000);
    }

    /** @param array<string, mixed>|null $presentation */
    private static function copy(
        ChatbotDraft $draft,
        ?int $retrievalTopK = null,
        ?float $minimumSimilarity = null,
        ?int $retentionDays = null,
        ?int $idleExpiryMinutes = null,
        ?int $absoluteExpiryMinutes = null,
        ?string $privacyNoticeUrl = null,
        ?array $presentation = null,
    ): ChatbotDraft {
        return new ChatbotDraft(
            $draft->schemaVersion,
            $draft->revision,
            $draft->systemInstructions,
            $draft->fallbackMessage,
            $retrievalTopK ?? $draft->retrievalTopK,
            $minimumSimilarity ?? $draft->minimumSimilarity,
            $draft->citationsEnabled,
            $draft->maximumMessageCharacters,
            $draft->maximumMessagesPerSession,
            $idleExpiryMinutes ?? $draft->idleExpiryMinutes,
            $absoluteExpiryMinutes ?? $draft->absoluteExpiryMinutes,
            $retentionDays ?? $draft->retentionDays,
            $privacyNoticeUrl ?? $draft->privacyNoticeUrl,
            $draft->disclosureText,
            $presentation ?? $draft->presentation,
            $draft->appearance,
        );
    }
}

