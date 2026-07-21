<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Logging\SecretRedactionProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class SecretRedactionProcessorTest extends TestCase
{
    public function testItRedactsSensitiveFieldsAndCredentialPatterns(): void
    {
        $processor = new SecretRedactionProcessor();
        $record = new LogRecord(
            new \DateTimeImmutable(),
            'test',
            Level::Error,
            'Authorization: Bearer rag_live_abcdefghijklmnopqrstuvwxyz',
            [
                'api_key' => 'sk-abcdefghijklmnop',
                'nested' => ['password' => 'do-not-log-this'],
                'diagnostic' => 'integration chatint_live_abcdefghijklmnopqrstuvwxyz and session cst_v1_abcdefghijklmnopqrstuvwxyz',
                'exception' => new \RuntimeException('token=super-secret-token'),
            ],
        );

        $redacted = $processor($record);

        self::assertStringNotContainsString('rag_live_', $redacted->message);
        self::assertSame('[REDACTED]', $redacted->context['api_key']);
        self::assertSame('[REDACTED]', $redacted->context['nested']['password']);
        self::assertSame('integration [REDACTED] and session [REDACTED]', $redacted->context['diagnostic']);
        self::assertSame('token=[REDACTED]', $redacted->context['exception']['message']);
        self::assertArrayNotHasKey('trace', $redacted->context['exception']);
    }
}
