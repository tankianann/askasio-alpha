<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ConfigurationException;
use App\Support\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testItReadsNestedValuesAndDefaults(): void
    {
        $config = new Config(['app' => ['name' => 'RAG Server']]);

        self::assertSame('RAG Server', $config->get('app.name'));
        self::assertSame('fallback', $config->get('app.missing', 'fallback'));
    }

    public function testRequiredStringRejectsAnEmptyValue(): void
    {
        $config = new Config(['app' => ['secret' => '']]);

        $this->expectException(ConfigurationException::class);
        $config->requireString('app.secret');
    }
}
