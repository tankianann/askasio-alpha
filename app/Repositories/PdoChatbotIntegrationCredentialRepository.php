<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use App\Domain\Chatbots\ChatbotIntegrationCredential;
use App\Support\Pagination\PageRequest;
use App\Support\Pagination\PaginatedResult;
use PDO;
use RuntimeException;
use Throwable;

final readonly class PdoChatbotIntegrationCredentialRepository implements ChatbotIntegrationCredentialRepositoryInterface
{
    public function __construct(private Connection $connection) {}

    public function paginate(PageRequest $page): PaginatedResult
    {
        $pdo = $this->connection->pdo();
        $total = (int) $pdo->query('SELECT COUNT(*) FROM chatbot_integration_credentials')->fetchColumn();
        $page = $page->clampToTotal($total);
        $rows = $pdo->query($this->select() . " GROUP BY cic.id ORDER BY cic.created_at DESC, cic.id DESC LIMIT {$page->perPage} OFFSET {$page->offset()}")->fetchAll();
        return new PaginatedResult(array_map($this->hydrate(...), $rows), $total, $page);
    }

    public function findById(int $id): ?ChatbotIntegrationCredential
    { $s = $this->connection->pdo()->prepare($this->select() . ' WHERE cic.id = :id GROUP BY cic.id LIMIT 1'); $s->execute(['id' => $id]); $row = $s->fetch(); return is_array($row) ? $this->hydrate($row) : null; }
    public function findByHash(string $hash): ?ChatbotIntegrationCredential
    { $s = $this->connection->pdo()->prepare($this->select(true) . ' WHERE cic.secret_hash = :hash GROUP BY cic.id LIMIT 1'); $s->execute(['hash' => $hash]); $row = $s->fetch(); return is_array($row) ? $this->hydrate($row) : null; }

    public function create(int $adminId, string $name, string $prefix, string $hash, ?string $expiresAt, array $chatbotIds): ChatbotIntegrationCredential
    {
        $pdo = $this->connection->pdo();
        $owned = !$pdo->inTransaction(); if ($owned) { $pdo->beginTransaction(); }
        try {
            $s = $pdo->prepare("INSERT INTO chatbot_integration_credentials (created_by_admin_id,name,visible_prefix,secret_hash,status,expires_at) VALUES (:admin,:name,:prefix,:hash,'active',:expires)");
            $s->execute(['admin' => $adminId, 'name' => $name, 'prefix' => $prefix, 'hash' => $hash, 'expires' => $expiresAt]);
            $id = (int) $pdo->lastInsertId();
            $scope = $pdo->prepare('INSERT INTO chatbot_integration_credential_scopes (credential_id,chatbot_id) VALUES (:credential,:chatbot)');
            foreach ($chatbotIds as $chatbotId) { $scope->execute(['credential' => $id, 'chatbot' => $chatbotId]); }
            if ($owned) { $pdo->commit(); }
        } catch (Throwable $e) { if ($owned && $pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
        return $this->findById($id) ?? throw new RuntimeException('Integration credential could not be loaded.');
    }

    public function touchUsage(int $id, int $providerTokens = 0): void
    { $s = $this->connection->pdo()->prepare('UPDATE chatbot_integration_credentials SET last_used_at=UTC_TIMESTAMP(6), request_count=request_count+1, provider_tokens=provider_tokens+:tokens WHERE id=:id'); $s->execute(['id' => $id, 'tokens' => max(0, $providerTokens)]); }
    public function addProviderTokens(int $id, int $providerTokens): void
    { $s = $this->connection->pdo()->prepare('UPDATE chatbot_integration_credentials SET provider_tokens=provider_tokens+:tokens WHERE id=:id'); $s->execute(['id'=>$id,'tokens'=>max(0,$providerTokens)]); }
    public function revoke(int $id): void
    { $s = $this->connection->pdo()->prepare("UPDATE chatbot_integration_credentials SET status='revoked', revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP(6)) WHERE id=:id"); $s->execute(['id' => $id]); }
    public function delete(int $id): void
    { $s = $this->connection->pdo()->prepare('DELETE FROM chatbot_integration_credentials WHERE id=:id'); $s->execute(['id' => $id]); }

    private function select(bool $includeHash = false): string
    {
        return 'SELECT cic.id,cic.created_by_admin_id,cic.name,cic.visible_prefix,' . ($includeHash ? 'cic.secret_hash' : "'' AS secret_hash") . ',cic.status,cic.request_count,cic.provider_tokens,cic.created_at,cic.last_used_at,cic.expires_at,cic.revoked_at,GROUP_CONCAT(cics.chatbot_id ORDER BY cics.chatbot_id) AS chatbot_ids FROM chatbot_integration_credentials cic LEFT JOIN chatbot_integration_credential_scopes cics ON cics.credential_id=cic.id';
    }
    /** @param array<string,mixed> $row */
    private function hydrate(array $row): ChatbotIntegrationCredential
    {
        $ids = $row['chatbot_ids'] === null ? [] : array_map('intval', explode(',', (string) $row['chatbot_ids']));
        return new ChatbotIntegrationCredential((int)$row['id'],(int)$row['created_by_admin_id'],(string)$row['name'],(string)$row['visible_prefix'],(string)$row['secret_hash'],(string)$row['status'],$ids,(int)$row['request_count'],(int)$row['provider_tokens'],(string)$row['created_at'],isset($row['last_used_at'])?(string)$row['last_used_at']:null,isset($row['expires_at'])?(string)$row['expires_at']:null,isset($row['revoked_at'])?(string)$row['revoked_at']:null);
    }
}
