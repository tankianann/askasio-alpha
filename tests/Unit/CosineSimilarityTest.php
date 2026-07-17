<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\RAG\CosineSimilarity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CosineSimilarityTest extends TestCase
{
    #[DataProvider('vectors')]
    public function testItCalculatesCosineSimilarity(array $left, array $right, float $expected): void
    {
        self::assertEqualsWithDelta($expected, (new CosineSimilarity())->calculate($left, $right), 0.000001);
    }

    public static function vectors(): array
    {
        return [
            'identical' => [[1.0, 2.0], [1.0, 2.0], 1.0],
            'orthogonal' => [[1.0, 0.0], [0.0, 1.0], 0.0],
            'opposite' => [[1.0, 0.0], [-1.0, 0.0], -1.0],
            'zero magnitude' => [[0.0, 0.0], [1.0, 2.0], 0.0],
        ];
    }

    public function testItRejectsDimensionMismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CosineSimilarity())->calculate([1.0], [1.0, 2.0]);
    }
}
