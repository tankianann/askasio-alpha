<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Chatbots\ChatbotStatus;
use App\Domain\Chatbots\ChatbotSourceReadinessStatus;
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
        $repository->defineSource(1);
        $service = $this->service($repository, ['cb_first', 'cb_rotated']);
        $chatbot = $service->create('  Website support  ', '  Internal description  ', ChatbotFixtures::draft());

        self::assertSame('cb_first', $chatbot->publicId);
        self::assertSame('Website support', $chatbot->name);
        self::assertSame(1, $chatbot->draft->revision);
        self::assertFalse($chatbot->isPublished());

        $assigned = $service->updateAssignments(
            $chatbot->id,
            1,
            [1, 1],
            ['https://EXAMPLE.com:443/', 'https://example.com'],
        );
        self::assertSame(2, $assigned->draft->revision);
        self::assertSame([1], $assigned->assignments->sourceIds);
        self::assertSame(['https://example.com'], $assigned->assignments->origins);

        $first = $service->publish($chatbot->id);
        self::assertSame(1, $first->publicationNumber);
        self::assertSame('gpt-test-chat', $first->providerConfiguration->chatModel);
        self::assertSame('Support assistant', $first->configuration->presentation['display_name']);

        $updated = $service->updateDraft(
            $chatbot->id,
            2,
            'Website support',
            null,
            ChatbotFixtures::draft(displayName: 'Updated assistant'),
        );
        self::assertSame(3, $updated->draft->revision);
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
        $repository->defineSource(1);
        $service = $this->service($repository, ['cb_first']);
        $chatbot = $service->create('Support', null, ChatbotFixtures::draft());
        $service->updateAssignments($chatbot->id, 1, [1], ['https://example.com']);
        $service->publish($chatbot->id);

        try {
            $service->publish($chatbot->id);
            self::fail('An unchanged active publication should be rejected.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $service->updateDraft($chatbot->id, 2, 'Support', null, ChatbotFixtures::draft(displayName: 'New'));

        $this->expectException(StaleChatbotDraftException::class);
        $service->updateDraft($chatbot->id, 1, 'Support', null, ChatbotFixtures::draft(displayName: 'Stale'));
    }

    public function testLifecycleRequiresPublicationAndArchiveBeforeDeletion(): void
    {
        $repository = new InMemoryChatbotRepository();
        $repository->defineSource(1);
        $service = $this->service($repository, ['cb_first']);
        $chatbot = $service->create('Support', null, ChatbotFixtures::draft());
        $service->disable($chatbot->id);

        try {
            $service->enable($chatbot->id);
            self::fail('An unpublished chatbot must not be enabled.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $service->updateAssignments($chatbot->id, 1, [1], ['https://example.com']);
        $service->publish($chatbot->id);
        self::assertSame(ChatbotStatus::Disabled, $repository->findById($chatbot->id)?->status);
        self::assertSame(ChatbotStatus::Active, $service->enable($chatbot->id)->status);
        self::assertSame(ChatbotStatus::Archived, $service->archive($chatbot->id)->status);

        $service->permanentlyDelete($chatbot->id);
        self::assertNull($repository->findById($chatbot->id));
    }

    public function testPublicationRejectsUnreadySourcesAndKeepsDraftMutable(): void
    {
        $repository = new InMemoryChatbotRepository();
        $repository->defineSource(7, ChatbotSourceReadinessStatus::EmbeddingIncompatible);
        $service = $this->service($repository, ['cb_first']);
        $chatbot = $service->create('Support', null, ChatbotFixtures::draft());
        $updated = $service->updateAssignments($chatbot->id, 1, [7], ['https://example.com']);

        self::assertSame(2, $updated->draft->revision);
        self::assertNull($updated->activePublicationId);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('embedding incompatible');
        $service->publish($chatbot->id);
    }

    public function testAssignmentRejectsUnknownSourcesAsValidationFailure(): void
    {
        $repository = new InMemoryChatbotRepository();
        $service = $this->service($repository, ['cb_first']);
        $chatbot = $service->create('Support', null, ChatbotFixtures::draft());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('existing sources');
        $service->updateAssignments($chatbot->id, 1, [999], ['https://example.com']);
    }

    public function testEachPublicationFreezesOnlyItsOwnAssignedSources(): void
    {
        $repository = new InMemoryChatbotRepository();
        $repository->defineSource(1);
        $repository->defineSource(2);
        $service = $this->service($repository, ['cb_first', 'cb_second']);
        $first = $service->create('First', null, ChatbotFixtures::draft());
        $second = $service->create('Second', null, ChatbotFixtures::draft());
        $service->updateAssignments($first->id, 1, [1], ['https://first.example.com']);
        $service->updateAssignments($second->id, 1, [2], ['https://second.example.com']);

        $firstPublication = $service->publish($first->id);
        $secondPublication = $service->publish($second->id);

        self::assertSame([1], $firstPublication->assignments->sourceIds);
        self::assertSame([2], $secondPublication->assignments->sourceIds);
        self::assertNotContains(2, $firstPublication->assignments->sourceIds);
        self::assertNotContains(1, $secondPublication->assignments->sourceIds);
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
