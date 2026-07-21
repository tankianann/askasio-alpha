<?php

declare(strict_types=1);

namespace App\Services\Chatbots;

use App\Domain\Chatbots\ChatbotExecutionAudience;
use App\Domain\Chatbots\ChatbotSessionChannel;
use App\Domain\Api\ApiAccessMethod;
use App\Domain\ProviderQuota\ProviderQuotaAttribution;
use App\Domain\Chatbots\ChatbotExecutionConfiguration;
use App\Domain\Chatbots\ChatbotExecutionResult;
use App\Domain\Chatbots\ChatbotMessage;
use App\Domain\Chatbots\ChatbotMessageCompletion;
use App\Domain\Chatbots\ChatbotMessageReservation;
use App\Domain\Chatbots\ChatbotMessageReservationState;
use App\Domain\Chatbots\ChatbotProviderConfiguration;
use App\Domain\Chatbots\ChatbotStatus;
use App\Domain\RAG\RetrievedChunk;
use App\Exceptions\ChatbotExecutionException;
use App\Exceptions\ChatbotMessageInProgressException;
use App\Exceptions\StaleChatbotPublicationException;
use App\Ingestion\Chunking\HeuristicTokenEstimator;
use App\RAG\AnswerGenerator;
use App\RAG\ChatExecutionErrorMapper;
use App\RAG\CitationProjector;
use App\Repositories\ChatbotConversationRepositoryInterface;
use App\Repositories\ChatbotRepositoryInterface;
use App\Services\ProviderQuota\ProviderQuotaService;
use App\Services\ProviderQuota\ProviderUsageAccumulator;
use DateTimeImmutable;
use Throwable;

final readonly class SharedChatExecutionService
{
    public function __construct(
        private ChatbotRepositoryInterface $chatbots,
        private ChatbotConversationRepositoryInterface $conversations,
        private AnswerGenerator $answers,
        private ChatbotHistorySelector $history,
        private CitationProjector $citations,
        private ChatExecutionErrorMapper $errors,
        private ProviderQuotaService $quotas,
        private ProviderUsageAccumulator $providerUsage,
        private ChatbotProviderConfiguration $installationProvider,
        private HeuristicTokenEstimator $tokens,
        private int $maximumContextTokens,
        private int $maximumOutputTokens,
    ) {
        if ($this->maximumContextTokens < 256 || $this->maximumOutputTokens < 1) {
            throw new \InvalidArgumentException('Shared chatbot execution token limits are invalid.');
        }
    }

    public function execute(
        ChatbotMessageReservation $reservation,
        ChatbotExecutionAudience $audience,
        string $safetyIdentifier,
        DateTimeImmutable $now,
    ): ChatbotExecutionResult {
        if ($reservation->state === ChatbotMessageReservationState::Replay) {
            return $this->replay($reservation, $audience);
        }

        if ($reservation->state === ChatbotMessageReservationState::InProgress) {
            throw new ChatbotMessageInProgressException('The chatbot message is already being processed.');
        }

        if ($reservation->userMessage->content === null) {
            throw new \LogicException('A reserved chatbot user message must contain content.');
        }

        $quota = null;
        $safeFailure = null;
        $chargedTokens = 0;

        try {
            if (($audience === ChatbotExecutionAudience::AdminPreview
                    && ($reservation->session->channel !== ChatbotSessionChannel::AdminPreview
                        || !$reservation->session->isTest))
                || ($audience === ChatbotExecutionAudience::Public
                    && $reservation->session->channel === ChatbotSessionChannel::AdminPreview)) {
                throw new ChatbotExecutionException(403, 'execution_audience_mismatch', 'The chatbot session cannot be used for this execution audience.');
            }

            $chatbot = $this->chatbots->findById($reservation->session->chatbotId);

            $available = $chatbot !== null && match ($audience) {
                ChatbotExecutionAudience::Public => $chatbot->status === ChatbotStatus::Active,
                ChatbotExecutionAudience::AdminPreview => $chatbot->status !== ChatbotStatus::Archived,
            };

            if (!$available) {
                throw new ChatbotExecutionException(404, 'chatbot_unavailable', 'The chatbot is not available.');
            }

            if ($reservation->session->publicationId !== null) {
                $publication = $this->chatbots->findPublicationById($reservation->session->publicationId);
                $execution = $publication === null ? null : new ChatbotExecutionConfiguration(
                    $publication->chatbotId,
                    $publication->id,
                    null,
                    $publication->configuration,
                    $publication->assignments,
                    $publication->providerConfiguration,
                );
            } else {
                $execution = $this->conversations->previewExecutionConfiguration($reservation->session->id);
            }

            if (!$execution instanceof ChatbotExecutionConfiguration || $execution->chatbotId !== $chatbot->id) {
                throw new ChatbotExecutionException(503, 'execution_configuration_unavailable', 'The chatbot execution configuration is unavailable.');
            }

            if ($execution->providerConfiguration->configuration()
                !== $this->installationProvider->configuration()) {
                throw new StaleChatbotPublicationException('The publication provider configuration is stale.');
            }

            $selectedHistory = $this->history->select(
                $this->conversations->messagesForSession($reservation->session->id),
                $reservation->userMessage->id,
            );
            $question = $reservation->userMessage->content;
            $quota = $this->quotas->reserveInstallationChat(
                $question,
                $this->maximumContextTokens + $this->history->maximumTokens(),
                $this->maximumOutputTokens,
                new ProviderQuotaAttribution(
                    match ($reservation->session->channel) {
                        ChatbotSessionChannel::Browser => ApiAccessMethod::BrowserChatbot,
                        ChatbotSessionChannel::Integration => ApiAccessMethod::ChatbotApiKey,
                        ChatbotSessionChannel::AdminPreview => ApiAccessMethod::AdminPreview,
                    },
                    chatbotApiKeyId: $reservation->session->chatbotApiKeyId,
                    chatbotId: $reservation->session->chatbotId,
                ),
            );
            $embeddingBefore = $this->providerUsage->embeddingTokens();
            $started = hrtime(true);
            $answer = $this->answers->generateConfigured(
                $question,
                $execution->configuration->retrievalTopK,
                [
                    'source_ids' => $execution->assignments->sourceIds,
                    'minimum_similarity' => $execution->configuration->minimumSimilarity,
                ],
                $execution->configuration->systemInstructions,
                $execution->configuration->fallbackMessage,
                $selectedHistory->messages,
                ['safety_identifier' => substr($safetyIdentifier, 0, 64)],
            );
            $latencyMs = max(0, (int) ((hrtime(true) - $started) / 1_000_000));
            $embeddingTokens = max(0, $this->providerUsage->embeddingTokens() - $embeddingBefore);

            if ($embeddingTokens === 0) {
                $embeddingTokens = $this->tokens->estimate($question);
            }

            $chatTokens = $answer->usage['total_tokens'] ?? 0;
            $providerTokens = $chatTokens + $embeddingTokens;
            $publicCitations = $execution->configuration->citationsEnabled
                ? array_map(
                    fn (RetrievedChunk $chunk, int $index): array => $this->citations->public($chunk, $index),
                    $answer->chunks,
                    array_keys($answer->chunks),
                )
                : [];
            $displayAnswer = $execution->configuration->citationsEnabled
                ? $answer->answer
                : trim(preg_replace('/\s*\[S\d+\]/', '', $answer->answer) ?? $answer->answer);
            $retrieval = [
                'policy' => 'assigned_active_sources_v1',
                'fallback' => $answer->fallback,
                'retrieved_chunks' => $answer->usage['retrieved_chunks'] ?? 0,
                'context_tokens' => $answer->usage['context_tokens'] ?? 0,
                'history_policy' => $selectedHistory->policy,
                'history_messages' => count($selectedHistory->messages),
                'history_tokens' => $selectedHistory->estimatedTokens,
                'matches' => array_map(
                    fn (RetrievedChunk $chunk, int $index): array => $this->citations->diagnostic($chunk, $index),
                    $answer->chunks,
                    array_keys($answer->chunks),
                ),
            ];
            $chargedTokens = $providerTokens;
            $quotaUsage = $this->quotas->reconcile($quota, $providerTokens);
            $usage = [
                ...$answer->usage,
                'embedding_tokens' => $embeddingTokens,
                'provider_total_tokens' => $providerTokens,
                ...$quotaUsage,
            ];
            $message = $this->conversations->completeMessage(
                $reservation->session->id,
                $reservation->userMessage->id,
                new ChatbotMessageCompletion(
                    $displayAnswer,
                    $execution->providerConfiguration->chatProvider,
                    $answer->model ?? $execution->providerConfiguration->chatModel,
                    $latencyMs,
                    $answer->usage['input_tokens'] ?? 0,
                    $answer->usage['output_tokens'] ?? 0,
                    $embeddingTokens,
                    $providerTokens,
                    $retrieval,
                    $publicCitations,
                ),
                $now,
            );

            return new ChatbotExecutionResult(
                $message,
                $displayAnswer,
                $publicCitations,
                $audience === ChatbotExecutionAudience::AdminPreview ? $retrieval : [],
                $usage,
                $answer->fallback,
                false,
            );
        } catch (Throwable $exception) {
            if ($quota !== null) {
                $chargedTokens = $chargedTokens > 0 ? $chargedTokens : $quota->reservedTokens;

                try {
                    $this->quotas->reconcile($quota, $chargedTokens, true);
                } catch (Throwable) {
                    // The expiring reservation retains a conservative charge when immediate reconciliation fails.
                }
            }

            if ($exception instanceof ChatbotExecutionException) {
                $safeFailure = $exception;
            } elseif ($exception instanceof StaleChatbotPublicationException) {
                $safeFailure = new ChatbotExecutionException(
                    503,
                    'publication_configuration_stale',
                    'The chatbot configuration must be reviewed and republished.',
                    $exception,
                );
            } else {
                $mapped = $this->errors->map($exception);
                $safeFailure = $mapped === null
                    ? new ChatbotExecutionException(503, 'execution_error', 'The chatbot request could not be completed.', $exception)
                    : new ChatbotExecutionException(
                        $mapped->statusCode,
                        $mapped->errorCode,
                        $mapped->safeMessage,
                        $exception,
                    );
            }

            try {
                $this->conversations->failMessage(
                    $reservation->session->id,
                    $reservation->userMessage->id,
                    $safeFailure->errorCode,
                    $chargedTokens,
                    $now,
                );
            } catch (Throwable) {
                // Preserve the safe execution failure; stale-pending recovery owns an unavailable persistence layer.
            }

            throw $safeFailure;
        }
    }

    private function replay(
        ChatbotMessageReservation $reservation,
        ChatbotExecutionAudience $audience,
    ): ChatbotExecutionResult {
        $message = $reservation->assistantMessage;

        if (!$message instanceof ChatbotMessage) {
            throw new \LogicException('A replay reservation must contain its assistant outcome.');
        }

        if ($message->content === null) {
            throw new ChatbotExecutionException(
                409,
                $message->errorCode ?? 'message_failed',
                'The previous chatbot message attempt did not complete successfully.',
            );
        }

        $retrieval = $message->retrieval ?? [];
        $citations = $message->citations ?? [];

        return new ChatbotExecutionResult(
            $message,
            $message->content,
            $citations,
            $audience === ChatbotExecutionAudience::AdminPreview ? $retrieval : [],
            [
                'input_tokens' => $message->inputTokens,
                'output_tokens' => $message->outputTokens,
                'embedding_tokens' => $message->embeddingTokens,
                'provider_total_tokens' => $message->providerTokens,
            ],
            ($retrieval['fallback'] ?? false) === true,
            true,
        );
    }
}
