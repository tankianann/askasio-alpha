<?php

declare(strict_types=1);

namespace App\Services\Chatbots;

use App\Domain\Chatbots\Chatbot;
use App\Domain\Chatbots\ChatbotAssignments;
use App\Domain\Chatbots\ChatbotDraft;
use App\Domain\Chatbots\ChatbotProviderConfiguration;
use App\Domain\Chatbots\ChatbotPublication;
use App\Domain\Chatbots\ChatbotStatus;
use App\Exceptions\ValidationException;
use App\Exceptions\ChatbotPublicationReadinessException;
use App\Exceptions\InvalidChatbotSourceAssignmentException;
use App\Exceptions\UnchangedChatbotPublicationException;
use App\Repositories\ChatbotRepositoryInterface;

final readonly class ChatbotService
{
    public function __construct(
        private ChatbotRepositoryInterface $chatbots,
        private ChatbotDraftValidator $drafts,
        private ChatbotPublicIdGeneratorInterface $publicIds,
        private ChatbotProviderConfiguration $provider,
        private ChatbotOriginNormalizer $origins = new ChatbotOriginNormalizer(),
        private bool $providerAvailable = true,
    ) {
        $this->validateProvider($provider);
    }

    /** @param list<int> $sourceIds @param list<string> $origins */
    public function updateAssignments(
        int $id,
        int $expectedRevision,
        array $sourceIds,
        array $origins,
    ): Chatbot {
        if ($id < 1 || $expectedRevision < 1) {
            throw new \InvalidArgumentException('Valid chatbot and draft revision identifiers are required.');
        }

        $chatbot = $this->requireChatbot($id);

        if ($chatbot->status === ChatbotStatus::Archived) {
            throw new ValidationException('An archived chatbot draft cannot be changed.');
        }

        try {
            return $this->chatbots->replaceDraftAssignments(
                $id,
                $expectedRevision,
                new ChatbotAssignments($this->normalizeSourceIds($sourceIds), $this->origins->normalizeMany($origins)),
            );
        } catch (InvalidChatbotSourceAssignmentException $exception) {
            throw new ValidationException($exception->getMessage(), previous: $exception);
        }
    }

    public function create(string $name, ?string $description, ChatbotDraft $draft): Chatbot
    {
        [$name, $description] = $this->identity($name, $description);

        return $this->chatbots->create(
            $this->publicIds->generate(),
            $name,
            $description,
            $this->drafts->validateAndNormalize($draft),
        );
    }

    public function updateDraft(
        int $id,
        int $expectedRevision,
        string $name,
        ?string $description,
        ChatbotDraft $draft,
    ): Chatbot {
        if ($id < 1 || $expectedRevision < 1) {
            throw new \InvalidArgumentException('Valid chatbot and draft revision identifiers are required.');
        }

        [$name, $description] = $this->identity($name, $description);

        if ($this->requireChatbot($id)->status === ChatbotStatus::Archived) {
            throw new ValidationException('An archived chatbot draft cannot be changed.');
        }

        return $this->chatbots->updateDraft(
            $id,
            $expectedRevision,
            $name,
            $description,
            $this->drafts->validateAndNormalize($draft),
        );
    }

    public function publish(int $id): ChatbotPublication
    {
        if (!$this->providerAvailable) {
            throw new ValidationException('Configure the installation chat and embedding models before publishing.');
        }

        $chatbot = $this->requireChatbot($id);

        if ($chatbot->status === ChatbotStatus::Archived) {
            throw new ValidationException('An archived chatbot cannot be published.');
        }

        $draft = $this->drafts->validateAndNormalize($chatbot->draft);
        if ($chatbot->assignments->sourceIds === [] || $chatbot->assignments->origins === []) {
            throw new ValidationException('Publishing requires at least one assigned source and one allowed origin.');
        }

        $configurationHash = $this->configurationHash($draft, $chatbot->assignments, $this->provider);
        $active = $this->chatbots->findActivePublication($id);

        if ($active instanceof ChatbotPublication && hash_equals($active->configurationHash, $configurationHash)) {
            throw new ValidationException('The active chatbot publication already has this configuration.');
        }

        try {
            return $this->chatbots->publish(
                $id,
                $chatbot->draft->revision,
                $draft,
                $chatbot->assignments,
                $this->provider,
                $configurationHash,
            );
        } catch (UnchangedChatbotPublicationException|InvalidChatbotSourceAssignmentException $exception) {
            throw new ValidationException($exception->getMessage(), previous: $exception);
        } catch (ChatbotPublicationReadinessException $exception) {
            $failures = array_map(
                static fn ($source): string => sprintf(
                    '%s (%s)',
                    $source->sourceName ?? 'source #' . $source->sourceId,
                    str_replace('_', ' ', $source->status->value),
                ),
                array_values(array_filter($exception->sources, static fn ($source): bool => !$source->isReady())),
            );

            throw new ValidationException(
                'Assigned sources are not publishable: ' . implode(', ', $failures) . '.',
                previous: $exception,
            );
        }
    }

    public function rotatePublicId(int $id): Chatbot
    {
        $chatbot = $this->requireChatbot($id);

        if ($chatbot->status === ChatbotStatus::Archived) {
            throw new ValidationException('An archived chatbot public ID cannot be rotated.');
        }

        return $this->chatbots->rotatePublicId($id, $this->publicIds->generate());
    }

    public function disable(int $id): Chatbot
    {
        $chatbot = $this->requireChatbot($id);

        if ($chatbot->status === ChatbotStatus::Archived) {
            throw new ValidationException('An archived chatbot cannot be disabled.');
        }

        return $this->chatbots->setStatus($id, ChatbotStatus::Disabled);
    }

    public function enable(int $id): Chatbot
    {
        $chatbot = $this->requireChatbot($id);

        if ($chatbot->status === ChatbotStatus::Archived) {
            throw new ValidationException('An archived chatbot cannot be enabled.');
        }

        if (!$chatbot->isPublished()) {
            throw new ValidationException('A chatbot must have an active publication before it can be enabled.');
        }

        return $this->chatbots->setStatus($id, ChatbotStatus::Active);
    }

    public function archive(int $id): Chatbot
    {
        $this->requireChatbot($id);

        return $this->chatbots->setStatus($id, ChatbotStatus::Archived);
    }

    public function permanentlyDelete(int $id): void
    {
        $chatbot = $this->requireChatbot($id);

        if ($chatbot->status !== ChatbotStatus::Archived) {
            throw new ValidationException('A chatbot must be archived before permanent deletion.');
        }

        $this->chatbots->permanentlyDelete($id);
    }

    /** @return array{string, ?string} */
    private function identity(string $name, ?string $description): array
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 190) {
            throw new ValidationException('Chatbot name must contain between 1 and 190 characters.');
        }

        $description = $description === null ? null : trim($description);

        if ($description === '') {
            $description = null;
        }

        if ($description !== null && mb_strlen($description) > 2_000) {
            throw new ValidationException('Chatbot description may not exceed 2000 characters.');
        }

        return [$name, $description];
    }

    private function validateProvider(ChatbotProviderConfiguration $provider): void
    {
        foreach ([$provider->chatProvider, $provider->embeddingProvider] as $value) {
            $this->validateProviderValue($value, 50);
        }

        foreach ([$provider->chatModel, $provider->embeddingModel] as $value) {
            $this->validateProviderValue($value, 190);
        }

        if ($provider->embeddingDimensions !== null && $provider->embeddingDimensions < 1) {
            throw new \InvalidArgumentException('Embedding dimensions must be positive when configured.');
        }
    }

    private function validateProviderValue(string $value, int $maximumLength): void
    {
        if (trim($value) === ''
            || strlen($value) > $maximumLength
            || preg_match('/[^\x20-\x7E]/', $value) === 1) {
            throw new \InvalidArgumentException('Installation provider and model values must be bounded non-empty ASCII strings.');
        }
    }

    private function requireChatbot(int $id): Chatbot
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('A valid chatbot ID is required.');
        }

        $chatbot = $this->chatbots->findById($id);

        if (!$chatbot instanceof Chatbot) {
            throw new ValidationException('The chatbot does not exist.');
        }

        return $chatbot;
    }

    private function configurationHash(
        ChatbotDraft $draft,
        ChatbotAssignments $assignments,
        ChatbotProviderConfiguration $provider,
    ): string {
        return hash('sha256', json_encode(
            [
                'draft' => $draft->configuration(),
                'assignments' => $assignments->configuration(),
                'provider' => $provider->configuration(),
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @param list<int> $sourceIds @return list<int> */
    private function normalizeSourceIds(array $sourceIds): array
    {
        if (count($sourceIds) > 100) {
            throw new ValidationException('A chatbot may use at most 100 sources.');
        }

        foreach ($sourceIds as $sourceId) {
            if (!is_int($sourceId) || $sourceId < 1) {
                throw new ValidationException('Assigned source IDs must be positive integers.');
            }
        }

        $sourceIds = array_values(array_unique($sourceIds));
        sort($sourceIds, SORT_NUMERIC);

        return $sourceIds;
    }
}
