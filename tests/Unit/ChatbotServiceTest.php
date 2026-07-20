<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Chatbots\ChatbotStatus;
use App\Exceptions\StaleChatbotDraftException;
use App\Exceptions\ValidationException;
use App\Services\Chatbots\ChatbotDraftValidator;
use App\Services\Chatbots\ChatbotPublicIdGeneratorInterface;
use App\Services\Chatbots\ChatbotService;
use PHPUnit\Framework\TestCase;
use Tests\Fakes\InMemoryChatbotRepository;
use Tests\Support\ChatbotFixtures;

final class ChatbotServiceTest extends TestCase
{
    public function testItCreatesUpdatesAndPublishesImmutableConfiguration(): void
    {
        $repository = new InMemoryChatbotRepository();
        $service = $this->service($repository, ['cb_first', 'cb_rotated']);
        $chatbot = $service->create('  Website support  ', '  Internal description  ', ChatbotFixtures::draft());

        self::assertSame('cb_first', $chatbot->publicId);
        self::assertSame('Website support', $chatbot->name);
        self::assertSame(1, $chatbot->draft->revision);
        self::assertFalse($chatbot->isPublished());

        $first = $service->publish($chatbot->id);
        self::assertSame(1, $first->publicationNumber);
        self::assertSame('gpt-test-chat', $first->providerConfiguration->chatModel);
        self::assertSame('Support assistant', $first->configuration->presentation['display_name']);

        $updated = $service->updateDraft(
            $chatbot->id,
            1,
            'Website support',
            null,
            ChatbotFixtures::draft(displayName: 'Updated assistant'),
        );
        self::assertSame(2, $updated->draft->revision);
        self::assertSame('Support assistant', $first->configuration->presentation['display_name']);

        $second = $service->publish($chatbot->id);
        self::assertSame(2, $second->publicationNumber);
        self::assertNotSame($first->configurationHash, $second->configurationHash);
        self::assertSame('Updated assistant', $second->configuration->presentation['display_name']);

        $rotated = $service->rotatePublicId($chatbot->id);
        self::assertSame('cb_rotated', $rotated->publicId);
        self::assertNull($repository->findByPublicId('cb_first'));
    }

    public function testItRejectsDuplicatePublicationAndStaleDraftUpdate(): void
    {
        $repository = new InMemoryChatbotRepository();
        $service = $this->service($repository, ['cb_first']);
        $chatbot = $service->create('Support', null, ChatbotFixtures::draft());
        $service->publish($chatbot->id);

        try {
            $service->publish($chatbot->id);
            self::fail('An unchanged active publication should be rejected.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $service->updateDraft($chatbot->id, 1, 'Support', null, ChatbotFixtures::draft(displayName: 'New'));

        $this->expectException(StaleChatbotDraftException::class);
        $service->updateDraft($chatbot->id, 1, 'Support', null, ChatbotFixtures::draft(displayName: 'Stale'));
    }

    public function testLifecycleRequiresPublicationAndArchiveBeforeDeletion(): void
    {
        $repository = new InMemoryChatbotRepository();
        $service = $this->service($repository, ['cb_first']);
        $chatbot = $service->create('Support', null, ChatbotFixtures::draft());
        $service->disable($chatbot->id);

        try {
            $service->enable($chatbot->id);
            self::fail('An unpublished chatbot must not be enabled.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $service->publish($chatbot->id);
        self::assertSame(ChatbotStatus::Disabled, $repository->findById($chatbot->id)?->status);
        self::assertSame(ChatbotStatus::Active, $service->enable($chatbot->id)->status);
        self::assertSame(ChatbotStatus::Archived, $service->archive($chatbot->id)->status);

        $service->permanentlyDelete($chatbot->id);
        self::assertNull($repository->findById($chatbot->id));
    }

    public function testRandomGeneratorProducesOpaquePublicIdentifier(): void
    {
        $identifier = (new \App\Services\Chatbots\RandomChatbotPublicIdGenerator())->generate();

        self::assertMatchesRegularExpression('/^cb_[A-Za-z0-9_-]{43}$/', $identifier);
    }

    /** @param list<string> $identifiers */
    private function service(InMemoryChatbotRepository $repository, array $identifiers): ChatbotService
    {
        $generator = new class($identifiers) implements ChatbotPublicIdGeneratorInterface {
            /** @param list<string> $identifiers */
            public function __construct(private array $identifiers)
            {
            }

            public function generate(): string
            {
                return array_shift($this->identifiers) ?? throw new \RuntimeException('No test public ID remains.');
            }
        };

        return new ChatbotService(
            $repository,
            new ChatbotDraftValidator(8, 4_000),
            $generator,
            ChatbotFixtures::provider(),
        );
    }
}
