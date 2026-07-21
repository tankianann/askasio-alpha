<?php

declare(strict_types=1);

namespace App\Services\Chatbots;

use App\Domain\Chatbots\ChatbotIntegrationCredential;
use App\Domain\Chatbots\CreatedChatbotIntegrationCredential;
use App\Exceptions\ValidationException;
use App\Repositories\ChatbotIntegrationCredentialRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;

final readonly class ChatbotIntegrationCredentialService
{
    private const PREFIX = 'chatint_live_';
    public function __construct(private ChatbotIntegrationCredentialRepositoryInterface $credentials) {}

    /** @param list<int> $chatbotIds */
    public function create(int $adminId, string $name, ?string $expiresAt, array $chatbotIds): CreatedChatbotIntegrationCredential
    {
        $name = trim($name); $chatbotIds = array_values(array_unique($chatbotIds));
        if ($adminId < 1 || $name === '' || mb_strlen($name) > 190) { throw new ValidationException('Credential name must contain between 1 and 190 characters.'); }
        if ($chatbotIds === [] || count($chatbotIds) > 100 || array_filter($chatbotIds, static fn (int $id): bool => $id < 1) !== []) { throw new ValidationException('Select between 1 and 100 chatbots.'); }
        if ($expiresAt !== null) {
            $date = new DateTimeImmutable($expiresAt, new DateTimeZone('UTC'));
            if ($date <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) { throw new ValidationException('Credential expiry must be in the future.'); }
            $expiresAt = $date->format('Y-m-d H:i:s');
        }
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $plaintext = self::PREFIX . $secret;
        $credential = $this->credentials->create($adminId, $name, self::PREFIX . substr($secret, 0, 8), hash('sha256', $plaintext), $expiresAt, $chatbotIds);
        return new CreatedChatbotIntegrationCredential($credential, $plaintext);
    }

    public function authenticate(string $plaintext): ?ChatbotIntegrationCredential
    {
        if (!str_starts_with($plaintext, self::PREFIX) || strlen($plaintext) > 128) { return null; }
        $hash = hash('sha256', $plaintext); $credential = $this->credentials->findByHash($hash);
        return $credential instanceof ChatbotIntegrationCredential && hash_equals($credential->secretHash, $hash) && $credential->isUsable() ? $credential : null;
    }
}
