<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ValidationException;
use App\Services\Chatbots\ChatbotOriginNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChatbotOriginNormalizerTest extends TestCase
{
    public function testItCanonicalizesSortsAndDeduplicatesOrigins(): void
    {
        $origins = (new ChatbotOriginNormalizer())->normalizeMany([
            'https://B.example:8443/',
            'https://example.com:443',
            'https://EXAMPLE.com./',
            'http://[::1]:8080',
        ]);

        self::assertSame([
            'http://[::1]:8080',
            'https://b.example:8443',
            'https://example.com',
        ], $origins);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOrigins(): iterable
    {
        yield 'remote HTTP' => ['http://example.com'];
        yield 'path' => ['https://example.com/widget'];
        yield 'query' => ['https://example.com?x=1'];
        yield 'credentials' => ['https://user@example.com'];
        yield 'unsupported scheme' => ['ftp://example.com'];
    }

    #[DataProvider('invalidOrigins')]
    public function testItRejectsValuesThatAreNotStrictOrigins(string $origin): void
    {
        $this->expectException(ValidationException::class);
        (new ChatbotOriginNormalizer())->normalize($origin);
    }
}
