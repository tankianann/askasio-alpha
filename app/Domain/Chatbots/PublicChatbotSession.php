<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

use DateTimeImmutable;
use DateTimeZone;

final readonly class PublicChatbotSession
{
    public function __construct(
        public string $sessionId,
        public string $sessionToken,
        public string $idleExpiresAt,
        public string $absoluteExpiresAt,
    ) {
    }

    public static function fromCreated(CreatedChatbotSession $created): self
    {
        return new self(
            $created->session->publicId,
            $created->token,
            self::utc($created->session->idleExpiresAt),
            self::utc($created->session->absoluteExpiresAt),
        );
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'session_token' => $this->sessionToken,
            'idle_expires_at' => $this->idleExpiresAt,
            'absolute_expires_at' => $this->absoluteExpiresAt,
        ];
    }

    private static function utc(string $value): string
    {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
