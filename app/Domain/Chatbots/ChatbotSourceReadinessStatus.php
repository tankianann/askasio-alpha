<?php

declare(strict_types=1);

namespace App\Domain\Chatbots;

enum ChatbotSourceReadinessStatus: string
{
    case Ready = 'ready';
    case Missing = 'missing';
    case Disabled = 'disabled';
    case Deleted = 'deleted';
    case NoActiveVersion = 'no_active_version';
    case ActiveVersionNotReady = 'active_version_not_ready';
    case EmbeddingIncompatible = 'embedding_incompatible';
}

