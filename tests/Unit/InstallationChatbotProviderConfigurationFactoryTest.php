<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Chatbots\InstallationChatbotProviderConfigurationFactory;
use App\Support\Config;
use PHPUnit\Framework\TestCase;

final class InstallationChatbotProviderConfigurationFactoryTest extends TestCase
{
    public function testItUsesOnlyInstallationProviderAndModelConfiguration(): void
    {
        $configuration = (new InstallationChatbotProviderConfigurationFactory(new Config([
            'providers' => [
                'chat_provider' => 'openai',
                'embedding_provider' => 'openai',
                'openai' => [
                    'chat_model' => 'gpt-installation',
                    'embedding_model' => 'embedding-installation',
                    'embedding_dimensions' => 1536,
                ],
            ],
        ])))->create();

        self::assertSame('openai', $configuration->chatProvider);
        self::assertSame('gpt-installation', $configuration->chatModel);
        self::assertSame('openai', $configuration->embeddingProvider);
        self::assertSame('embedding-installation', $configuration->embeddingModel);
        self::assertSame(1536, $configuration->embeddingDimensions);
    }
}

