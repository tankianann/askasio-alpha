<?php

declare(strict_types=1);

namespace App\RAG;

use InvalidArgumentException;

final class CosineSimilarity
{
    /**
     * @param list<float|int> $left
     * @param list<float|int> $right
     */
    public function calculate(array $left, array $right): float
    {
        if ($left === [] || count($left) !== count($right)) {
            throw new InvalidArgumentException('Cosine similarity requires non-empty vectors with equal dimensions.');
        }

        $dotProduct = 0.0;
        $leftMagnitude = 0.0;
        $rightMagnitude = 0.0;

        foreach ($left as $index => $leftValue) {
            $rightValue = $right[$index] ?? null;

            if ((!is_int($leftValue) && !is_float($leftValue))
                || (!is_int($rightValue) && !is_float($rightValue))) {
                throw new InvalidArgumentException('Cosine similarity vectors must contain only numbers.');
            }

            $leftNumber = (float) $leftValue;
            $rightNumber = (float) $rightValue;

            if (!is_finite($leftNumber) || !is_finite($rightNumber)) {
                throw new InvalidArgumentException('Cosine similarity vectors must contain finite numbers.');
            }

            $dotProduct += $leftNumber * $rightNumber;
            $leftMagnitude += $leftNumber ** 2;
            $rightMagnitude += $rightNumber ** 2;
        }

        if ($leftMagnitude <= 0.0 || $rightMagnitude <= 0.0) {
            return 0.0;
        }

        $similarity = $dotProduct / (sqrt($leftMagnitude) * sqrt($rightMagnitude));

        return max(-1.0, min(1.0, $similarity));
    }
}
