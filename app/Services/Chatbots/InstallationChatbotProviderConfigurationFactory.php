<?php

declare(strict_types=1);

namespace App\Services\Chatbots;

use App\Domain\Chatbots\ChatbotProviderConfiguration;
use App\Exceptions\ConfigurationException;
use App\Support\Config;

final readonly class InstallationChatbotProviderConfigurationFactory
{
    public function __construct(private Config $config)
    {
    }

    public function create(): ChatbotProviderConfiguration
    {
        $dimensions = $this->config->get('providers.openai.embedding_dimensions');

        if ($dimensions !== null && (!is_int($dimensions) || $dimensions < 1)) {
            throw new ConfigurationException('OpenAI embedding dimensions must be positive when configured.');
        }

        return new ChatbotProviderConfiguration(
            $this->config->requireString('providers.chat_provider'),
            $this->config->requireString('providers.openai.chat_model'),
            $this->config->requireString('providers.embedding_provider'),
            $this->config->requireString('providers.openai.embedding_model'),
            $dimensions,
        );
    }
}

