<?php

declare(strict_types=1);

namespace App\Providers\Embeddings;

use App\Providers\OpenAI\OpenAiHttpClient;
use App\Providers\OpenAI\OpenAiConfigurationException;
use App\Support\Config;
use App\Services\ProviderQuota\ProviderUsageAccumulator;

final class EmbeddingProviderFactory
{
    public function __construct(private readonly Config $config)
    {
    }

    public function create(?ProviderUsageAccumulator $usage = null): EmbeddingProviderInterface
    {
        $provider = $this->config->requireString('providers.embedding_provider');

        if ($provider !== 'openai') {
            throw new EmbeddingConfigurationException(sprintf('Unsupported embedding provider: %s.', $provider));
        }

        $dimensions = $this->config->get('providers.openai.embedding_dimensions');

        if ($dimensions !== null && !is_int($dimensions)) {
            throw new EmbeddingConfigurationException('OpenAI embedding dimensions are configured incorrectly.');
        }

        try {
            $client = new OpenAiHttpClient(
                $this->config->requireString('providers.openai.api_key'),
                $this->config->requireString('providers.openai.base_url'),
                $this->config->requireInt('providers.openai.connect_timeout_seconds'),
                $this->config->requireInt('providers.openai.request_timeout_seconds'),
                $this->config->requireInt('providers.openai.maximum_retries'),
            );
        } catch (OpenAiConfigurationException $exception) {
            throw new EmbeddingConfigurationException($exception->getMessage(), previous: $exception);
        }

        return new OpenAiEmbeddingProvider(
            $client,
            $this->config->requireString('providers.openai.embedding_model'),
            $dimensions,
            $usage,
        );
    }
}
