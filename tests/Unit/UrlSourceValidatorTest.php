<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ValidationException;
use App\Security\UrlSourceValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlSourceValidatorTest extends TestCase
{
    public function testItAcceptsPublicHttpAndHttpsUrls(): void
    {
        $validator = new UrlSourceValidator();

        self::assertSame('https://example.com/policies/refunds', $validator->validate('https://example.com/policies/refunds'));
        self::assertSame('http://8.8.8.8/document', $validator->validate('http://8.8.8.8/document'));
    }

    #[DataProvider('unsafeUrls')]
    public function testItRejectsUnsafeOrUnsupportedUrls(string $url): void
    {
        $this->expectException(ValidationException::class);
        (new UrlSourceValidator())->validate($url);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeUrls(): iterable
    {
        yield 'file protocol' => ['file:///etc/passwd'];
        yield 'embedded credentials' => ['https://admin:secret@example.com'];
        yield 'localhost' => ['http://localhost/internal'];
        yield 'loopback IPv4' => ['http://127.0.0.1/admin'];
        yield 'private IPv4' => ['http://10.1.2.3/internal'];
        yield 'link local metadata' => ['http://169.254.169.254/latest/meta-data'];
        yield 'loopback IPv6' => ['http://[::1]/admin'];
        yield 'numeric alternate address' => ['http://2130706433/admin'];
    }
}
