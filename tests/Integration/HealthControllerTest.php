<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Controllers\Api\HealthController;
use App\Http\Request;
use PHPUnit\Framework\TestCase;

final class HealthControllerTest extends TestCase
{
    public function testHealthyDatabaseReturnsOkWithoutInfrastructureDetails(): void
    {
        $controller = new HealthController(static fn (): bool => true);
        $request = (new Request('GET', '/api/v1/health'))->withAttribute('request_id', 'test-request-id');

        $response = $controller($request);
        $payload = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->status());
        self::assertSame('ok', $payload['status']);
        self::assertSame('ok', $payload['services']['database']);
        self::assertSame('test-request-id', $payload['request_id']);
        self::assertArrayNotHasKey('host', $payload);
    }

    public function testUnavailableDatabaseReturnsADegradedServiceResponse(): void
    {
        $controller = new HealthController(static fn (): bool => throw new \PDOException('Connection details'));

        $response = $controller(new Request('GET', '/api/v1/health'));
        $payload = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(503, $response->status());
        self::assertSame('degraded', $payload['status']);
        self::assertSame('unavailable', $payload['services']['database']);
        self::assertStringNotContainsString('Connection details', $response->body());
    }
}
