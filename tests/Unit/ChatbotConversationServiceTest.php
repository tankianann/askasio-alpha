<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Chatbots\ChatbotMessageCompletion;
use App\Domain\Chatbots\ChatbotMessageReservationState;
use App\Domain\Chatbots\ChatbotMessageStatus;
use App\Domain\Chatbots\ChatbotSessionChannel;
use App\Domain\Chatbots\ChatbotSessionCredentials;
use App\Domain\Chatbots\ChatbotSessionStatus;
use App\Exceptions\ChatbotIdempotencyConflictException;
use App\Exceptions\ChatbotMessageLimitException;
use App\Exceptions\ChatbotSessionUnavailableException;
use App\Exceptions\InvalidChatbotSessionTokenException;
use App\Exceptions\ValidationException;
use App\Services\Chatbots\ChatbotConversationService;
use App\Services\Chatbots\ChatbotSessionCredentialGeneratorInterface;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryChatbotConversationRepository;

final class ChatbotConversationServiceTest extends TestCase
{
    public function testSessionCopiesPublicationPolicyAndStoresNoRecoverableToken(): void
    {
        [$service, $repository, $credentials] = $this->fixture(retentionDays: 7);
        $created = $service->createSession(
            1,
            ChatbotSessionChannel::Browser,
            'https://EXAMPLE.com:443/',
            false,
            new DateTimeImmutable('2026-07-20 10:00:00 UTC'),
        );

        self::assertSame($credentials->token, $created->token);
        self::assertSame(22, $created->session->publicationId);
        self::assertSame('https://example.com', $created->session->normalizedOrigin);
        self::assertSame(7, $created->session->retentionDays);
        self::assertSame(hash('sha256', $credentials->token), $created->session->tokenHash);
        self::assertStringNotContainsString($credentials->token, serialize($created->session));
        self::assertSame($created->session, $repository->findSessionByTokenHash($credentials->tokenHash));
    }

    public function testAuthenticationRequiresTheMatchingPublicIdAndHashOnlyToken(): void
    {
        [$service, , $credentials] = $this->fixture();
        $created = $service->createSession(
            1,
            ChatbotSessionChannel::Browser,
            'https://example.com',
            false,
            new DateTimeImmutable('2026-07-20 10:00:00 UTC'),
        );

        self::assertSame($created->session, $service->authenticate($credentials->publicId, $credentials->token));

        foreach ([
            ['cs_' . str_repeat('B', 43), $credentials->token],
            [$credentials->publicId, 'cst_v1_' . str_repeat('C', 43)],
            ['invalid', 'invalid'],
        ] as [$publicId, $token]) {
            try {
                $service->authenticate($publicId, $token);
                self::fail('Mismatched session credentials must be rejected.');
            } catch (InvalidChatbotSessionTokenException) {
                self::assertTrue(true);
            }
        }
    }

    public function testIntegrationSessionRetainsItsChatbotApiKeyAttribution(): void
    {
        [$service] = $this->fixture();
        $created = $service->createSession(
            1,
            ChatbotSessionChannel::Integration,
            null,
            false,
            new DateTimeImmutable('2026-07-20 10:00:00 UTC'),
            41,
        );

        self::assertSame(41, $created->session->chatbotApiKeyId);
    }

    public function testMessageReservationIsIdempotentAndAtomicallyCountsUserTurns(): void
    {
        [$service, $repository, $credentials] = $this->fixture(maximumMessages: 1);
        $created = $service->createSession(
            1,
            ChatbotSessionChannel::Browser,
            'https://example.com',
            false,
            new DateTimeImmutable('2026-07-20 10:00:00 UTC'),
        );
        $at = new DateTimeImmutable('2026-07-20 10:01:00 UTC');
        $reservation = $service->reserveMessage(
            $created->session->publicId,
            $credentials->token,
            'message-1',
            'How do I reset my password?',
            '018f9f3a-7420-7cc1-8a12-8ac550009999',
            $at,
        );

        self::assertSame(ChatbotMessageReservationState::Reserved, $reservation->state);
        self::assertSame(1, $reservation->session->messageCount);

        $inProgress = $service->reserveMessage(
            $created->session->publicId,
            $credentials->token,
            'message-1',
            'How do I reset my password?',
            '018f9f3a-7420-7cc1-8a12-8ac550009999',
            $at,
        );
        self::assertSame(ChatbotMessageReservationState::InProgress, $inProgress->state);
        self::assertSame($reservation->userMessage->id, $inProgress->userMessage->id);

        $assistant = $service->completeMessage(
            $reservation,
            new ChatbotMessageCompletion(
                'Use the reset link.', 'openai', 'gpt-test', 125, 20, 8, 3, 31,
                ['chunks' => [9]], [['source_id' => 4]],
            ),
            new DateTimeImmutable('2026-07-20 10:01:01 UTC'),
        );
        self::assertSame(ChatbotMessageStatus::Completed, $assistant->status);

        $replay = $service->reserveMessage(
            $created->session->publicId,
            $credentials->token,
            'message-1',
            'How do I reset my password?',
            '018f9f3a-7420-7cc1-8a12-8ac550009999',
            new DateTimeImmutable('2026-07-20 10:02:00 UTC'),
        );
        self::assertSame(ChatbotMessageReservationState::Replay, $replay->state);
        self::assertSame($assistant->id, $replay->assistantMessage?->id);
        self::assertCount(2, $repository->messagesForSession($created->session->id));

        $stored = $repository->findSessionByPublicId($created->session->publicId);
        self::assertSame(1, $stored?->messageCount);
        self::assertSame(20, $stored?->inputTokens);
        self::assertSame(31, $stored?->providerTokens);

        $this->expectException(ChatbotMessageLimitException::class);
        $service->reserveMessage(
            $created->session->publicId,
            $credentials->token,
            'message-2',
            'A concurrent second turn',
            '018f9f3a-7420-7cc1-8a12-8ac550008888',
            new DateTimeImmutable('2026-07-20 10:02:00 UTC'),
        );
    }

    public function testSameIdempotencyKeyCannotAuthorizeDifferentContent(): void
    {
        [$service, , $credentials] = $this->fixture();
        $created = $service->createSession(
            1, ChatbotSessionChannel::Browser, 'https://example.com', false,
            new DateTimeImmutable('2026-07-20 10:00:00 UTC'),
        );
        $service->reserveMessage(
            $created->session->publicId, $credentials->token, 'same-key', 'First content',
            '018f9f3a-7420-7cc1-8a12-8ac550001111', new DateTimeImmutable('2026-07-20 10:01:00 UTC'),
        );

        $this->expectException(ChatbotIdempotencyConflictException::class);
        $service->reserveMessage(
            $created->session->publicId, $credentials->token, 'same-key', 'Changed content',
            '018f9f3a-7420-7cc1-8a12-8ac550001111', new DateTimeImmutable('2026-07-20 10:01:01 UTC'),
        );
    }

    public function testExpiryAndCopiedRetentionProduceDeterministicPurgeEligibility(): void
    {
        [$service, $repository, $credentials] = $this->fixture(retentionDays: 7, idleMinutes: 10);
        $created = $service->createSession(
            1, ChatbotSessionChannel::Browser, 'https://example.com', false,
            new DateTimeImmutable('2026-07-20 10:00:00 UTC'),
        );

        try {
            $service->reserveMessage(
                $created->session->publicId, $credentials->token, 'late', 'Too late',
                '018f9f3a-7420-7cc1-8a12-8ac550002222', new DateTimeImmutable('2026-07-20 10:10:00 UTC'),
            );
            self::fail('An expired session must not accept a message.');
        } catch (ChatbotSessionUnavailableException) {
            self::assertSame(
                ChatbotSessionStatus::Expired,
                $repository->findSessionByPublicId($created->session->publicId)?->status,
            );
        }

        $expired = $repository->findSessionByPublicId($created->session->publicId);
        self::assertSame('2026-07-27 10:00:00.000000', $expired?->purgeEligibleAt);
        self::assertSame(0, $repository->purgeEligible(new DateTimeImmutable('2026-07-27 09:59:59 UTC'), 100));
        self::assertSame(1, $repository->purgeEligible(new DateTimeImmutable('2026-07-27 10:00:00 UTC'), 100));
        self::assertNull($repository->findSessionByPublicId($created->session->publicId));
    }

    public function testAdminPreviewMustBeTestTrafficAndBrowserMustBeProductionTraffic(): void
    {
        [$service] = $this->fixture(status: 'disabled');
        $preview = $service->createSession(
            1, ChatbotSessionChannel::AdminPreview, null, true,
            new DateTimeImmutable('2026-07-20 10:00:00 UTC'),
        );
        self::assertTrue($preview->session->isTest);

        foreach ([
            [ChatbotSessionChannel::AdminPreview, null, false],
            [ChatbotSessionChannel::Browser, 'https://example.com', true],
        ] as [$channel, $origin, $isTest]) {
            try {
                $service->createSession(1, $channel, $origin, $isTest, new DateTimeImmutable());
                self::fail('Invalid test classification must be rejected.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testZeroDayRetentionBecomesEligibleOnlyWhenSessionIsTerminal(): void
    {
        [$service, $repository, $credentials] = $this->fixture(retentionDays: 0);
        $created = $service->createSession(
            1, ChatbotSessionChannel::Browser, 'https://example.com', false,
            new DateTimeImmutable('2026-07-20 10:00:00 UTC'),
        );
        self::assertNull($created->session->purgeEligibleAt);
        self::assertSame(0, $repository->purgeEligible(new DateTimeImmutable('2026-07-20 10:01:00 UTC'), 100));

        $completed = $service->completeSession(
            $created->session->publicId,
            $credentials->token,
            new DateTimeImmutable('2026-07-20 10:02:00 UTC'),
        );
        self::assertSame(ChatbotSessionStatus::Completed, $completed->status);
        self::assertSame('2026-07-20 10:02:00.000000', $completed->purgeEligibleAt);
        self::assertSame(1, $repository->purgeEligible(new DateTimeImmutable('2026-07-20 10:02:00 UTC'), 100));
    }

    public function testRandomCredentialsContainIndependent256BitValues(): void
    {
        $credentials = (new \App\Services\Chatbots\RandomChatbotSessionCredentialGenerator())->generate();

        self::assertMatchesRegularExpression('/^cs_[A-Za-z0-9_-]{43}$/', $credentials->publicId);
        self::assertMatchesRegularExpression('/^cst_v1_[A-Za-z0-9_-]{43}$/', $credentials->token);
        self::assertSame(hash('sha256', $credentials->token), $credentials->tokenHash);
        self::assertSame(substr($credentials->token, 0, 15), $credentials->tokenPrefix);
    }

    /**
     * @return array{ChatbotConversationService, InMemoryChatbotConversationRepository, ChatbotSessionCredentials}
     */
    private function fixture(
        int $retentionDays = 30,
        int $maximumMessages = 3,
        int $idleMinutes = 30,
        string $status = 'active',
    ): array {
        $repository = new InMemoryChatbotConversationRepository();
        $repository->defineChatbot(
            1,
            publicationId: 22,
            status: $status,
            maximumMessages: $maximumMessages,
            idleMinutes: $idleMinutes,
            retentionDays: $retentionDays,
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

        return [new ChatbotConversationService($repository, $generator), $repository, $credentials];
    }
}
