<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Repositories\PdoApiKeyRepository;
use App\Repositories\PdoApiRequestLogRepository;
use App\Repositories\PdoSourceRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DashboardListProjectionPrivacyTest extends TestCase
{
    public function testApiAccessListProjectionOmitsSecretHashes(): void
    {
        $sql = $this->privateSql(PdoApiKeyRepository::class, 'listSelect');

        self::assertStringContainsString('visible_prefix', $sql);
        self::assertStringNotContainsString('secret_hash', $sql);
    }

    public function testApiActivityListProjectionOmitsIpHashesAndContentFields(): void
    {
        $sql = $this->privateSql(PdoApiRequestLogRepository::class, 'select');

        self::assertStringContainsString('logs.request_id', $sql);
        self::assertStringNotContainsString('logs.ip_hash', $sql);
        self::assertStringNotContainsString('question', $sql);
        self::assertStringNotContainsString('request_body', $sql);
        self::assertStringNotContainsString('answer', $sql);
    }

    public function testSourceHistoryProjectionOmitsExtractedDocumentFields(): void
    {
        $summary = $this->privateSql(PdoSourceRepository::class, 'versionSummarySelect');
        $detail = $this->privateSql(PdoSourceRepository::class, 'versionSelect');

        self::assertStringContainsString('chunk_count', $summary);
        self::assertStringNotContainsString('extracted_text', $summary);
        self::assertStringNotContainsString('metadata_json', $summary);
        self::assertStringContainsString('extracted_text', $detail);
        self::assertStringContainsString('metadata_json', $detail);
    }

    /** @param class-string $class */
    private function privateSql(string $class, string $method): string
    {
        $reflection = new ReflectionClass($class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $value = $reflection->getMethod($method)->invoke($instance);

        self::assertIsString($value);

        return $value;
    }
}
