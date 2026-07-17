<?php

declare(strict_types=1);

namespace App\Ingestion\Chunking;

final class HeuristicTokenEstimator
{
    public function estimate(string $text): int
    {
        $characters = mb_strlen(trim($text), 'UTF-8');

        return max(1, (int) ceil($characters / 4));
    }
}
