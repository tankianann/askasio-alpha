<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\Chatbots\ChatbotSourceDependency;

interface SourceDependencyRepositoryInterface
{
    /** @return list<ChatbotSourceDependency> */
    public function sourceDependencies(int $sourceId): array;
}

