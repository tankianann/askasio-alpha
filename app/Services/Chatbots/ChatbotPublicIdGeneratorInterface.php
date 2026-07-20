<?php

declare(strict_types=1);

namespace App\Services\Chatbots;

interface ChatbotPublicIdGeneratorInterface
{
    public function generate(): string;
}

