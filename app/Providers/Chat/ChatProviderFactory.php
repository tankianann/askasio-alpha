<?php

declare(strict_types=1);

namespace App\Providers\Chat;

use App\Providers\OpenAI\OpenAiConfigurationException;
use App\Providers\OpenAI\OpenAiHttpClient;
use App\Support\Config;

final class ChatProviderFactory
{
    public function __construct(private readonly Config $config)
    {
    }

    public function create(): ChatProviderInterface
    {
        $provider = $this->config->requireString('providers.chat_provider');

        if ($provider !== 'openai') {
            throw new ChatConfigurationException(sprintf('Unsupported chat provider: %s.', $provider));
        }

        try {
            $client = new OpenAiHttpClient(
                $this->config->requireString('providers.openai.api_key'),
                $this->config->requireString('providers.openai.base_url'),
                $this->config->requireInt('providers.openai.connect_timeout_seconds'),
                $this->config->requireInt('providers.openai.request_timeout_seconds'),
                $this->config->requireInt('providers.openai.chat_maximum_retries'),
            );
        } catch (OpenAiConfigurationException $exception) {
            throw new ChatConfigurationException($exception->getMessage(), previous: $exception);
        }

        $reasoningEffort = $this->config->get('providers.openai.chat_reasoning_effort');

        if ($reasoningEffort !== null && !is_string($reasoningEffort)) {
            throw new ChatConfigurationException('OpenAI chat reasoning effort is configured incorrectly.');
        }

        return new OpenAiChatProvider(
            $client,
            $this->config->requireString('providers.openai.chat_model'),
            $this->config->requireInt('providers.openai.chat_maximum_output_tokens'),
            $reasoningEffort,
        );
    }
}
