# Shared chat execution service

## Implementation status

Milestone 6 is implemented as one internal execution service for future administrator preview and public messaging. It adds no preview controller, public route, CORS behavior, widget, integration credential, or streaming protocol.

`SharedChatExecutionService` owns the execution of an already-authorized, atomically reserved conversation turn. Both future audiences pass through the same publication validation, source scope, history policy, grounding/fallback, provider mapping, persistence, and quota lifecycle. The audience changes only whether internal bounded retrieval diagnostics are returned; persisted/public citations remain safe.

The existing authenticated `POST /api/v1/chat` remains stateless, continues rejecting non-null `conversation_id`, and retains its request/response/error contract. Its grounding engine, provider error mapping, and legacy citation projection were refactored into shared components used by the chatbot executor.

## Code map

| Area | Implementation |
| --- | --- |
| Orchestration | `SharedChatExecutionService`. |
| Grounding | Extended `AnswerGenerator`, `Retriever`, `ContextSelector`, and `PromptBuilder`. |
| History | `ChatbotHistorySelector`, `ChatbotSelectedHistory`; recent completed pairs, oldest-first removal. |
| Projection | `CitationProjector`; separate legacy, public-safe, and administrator diagnostic projections. |
| Failures | `ChatExecutionErrorMapper`, `ChatbotExecutionException`, stable safe categories. |
| Quotas | Installation-wide chat reservation/reconciliation through the existing provider quota ledger. |
| Persistence | Existing conversation reservation/completion/failure transactions from milestone 5. |

## Execution contract

The caller must first authenticate/create the session and reserve a user turn through `ChatbotConversationService`. The executor accepts that `ChatbotMessageReservation`:

- `replay`: returns the persisted completed outcome without retrieval or provider access;
- `in_progress`: raises a stable in-progress exception without a duplicate provider call;
- `reserved`: executes exactly once and records a completed or failed assistant outcome.

HTTP authorization, rate limiting, origin revalidation, and conversion of these internal outcomes to public/admin DTOs remain their endpoint milestones.

## Publication and provider enforcement

Execution loads the session-bound immutable publication by ID, verifies it belongs to the session chatbot, and requires the chatbot's immediate status to remain `active`. A later publication does not silently change an existing session's instructions or scope.

The publication's provider, chat model, embedding provider/model, and dimensions must exactly equal the installation's current configuration. A mismatch fails before quota/provider access with `publication_configuration_stale`; review and republication are required. No per-chatbot provider credential or model selector was added.

Retrieval receives only server-derived publication values:

- exact immutable assigned source IDs;
- published top-K;
- published minimum similarity;
- current embedding model, added by `Retriever`.

The vector store continues enforcing enabled, non-deleted sources with ready active versions and compatible embedding dimensions/model. Client input cannot widen the scope.

## History policy and prompt safety

The initial history policy is accepted as `recent_completed_turns_v1`:

1. Pair a completed user message only with its completed assistant reply.
2. Exclude the current pending user message, failed/partial assistant outcomes, other sessions, diagnostics, and metadata.
3. Walk complete pairs newest-first under `RAG_CHAT_HISTORY_MAX_TOKENS` (default `1000`).
4. Return selected pairs in chronological order; when the budget is exceeded, older pairs are omitted rather than summarized or truncated.

The heuristic estimator remains conservative and known to be approximate. The provider quota reservation covers the configured source-context budget plus the full history budget and output allowance.

Core grounding instructions remain above administrator-authored chatbot instructions and explicitly cannot be overridden. Question, history, and source excerpts are JSON-encoded as untrusted data. History helps resolve the current question but is never evidence; admitted source excerpts remain the only factual authority.

## Fallback, citations, and diagnostics

If retrieval/context admission produces no chunks, the executor returns the publication's configured fallback and makes no chat-generation call. The embedding call and its usage are still recorded and reconciled.

Generated answers retain strict `[S#]` range validation. Public-safe citations contain only:

- stable reference;
- source display title;
- approved heading/page;
- URL only for URL sources.

They exclude source, version, and chunk IDs, similarity, excerpts, private paths, and arbitrary metadata. Administrator preview may receive a separate bounded diagnostic projection with internal IDs, rounded similarity, and a 280-character excerpt. Public execution results contain no diagnostic projection. When published citation display is disabled, citation markers are removed from the displayed/persisted answer and the citation list is empty; grounding and citation validation still occur before that display projection.

## Usage, quotas, and persistence

Before embedding/provider access, execution reserves the existing installation-wide daily/monthly provider budget. Installation-chat reservations use ledger subject ID `0` and create only global buckets; they do not impersonate or consume an API-key budget. Per-session/chatbot/integration rate or commercial limits remain later endpoint/credential work.

Successful execution records and reconciles:

- admitted chunk and context estimates;
- history policy/message/token estimates;
- actual chat input/output/provider-model usage;
- embedding usage, with the existing conservative estimator fallback when a provider adapter reports none;
- latency, fallback flag, safe citations, and bounded retrieval diagnostics;
- session input/output/embedding/provider aggregates.

The quota is reconciled before the completed assistant transaction. If execution becomes ambiguous after reservation, the full reservation is conservatively charged, matching existing `/api/v1/chat` behavior. If immediate reconciliation fails, the active reservation remains available to the existing expiry reconciler. A persisted replay prevents another provider call.

## Safe failure mapping

The shared mapper preserves the established categories and messages for configuration, authentication, rate-limit, timeout, malformed response, generic provider, and quota failures. Chatbot-specific safe codes add publication stale/unavailable, chatbot unavailable, in-progress, and generic execution failure.

Reserved conversation failures create a content-free failed assistant outcome with the safe code and conservative charged usage. Secret provider details are retained only in the exception chain for controlled operational handling; they are not used as public messages or stored transcript content.

## Existing API compatibility

`POST /api/v1/chat` still accepts only `question`, nullable `conversation_id`, and optional `top_k`; non-null conversation IDs remain `422`. It retains the same answer, legacy citation fields, usage fields, request ID, `Cache-Control: no-store`, status codes, and stable safe provider errors. It does not create chatbot sessions/messages and continues charging its authenticated API-key plus global quota scopes.

## Test coverage

- immutable assigned-source/top-K/similarity/model filters;
- stale publication rejection before quota or providers;
- recent completed-pair history, deterministic oldest removal, failed/current exclusion, and prompt separation;
- configured no-evidence fallback with no chat-generation call;
- public citation allowlist versus administrator diagnostics;
- completed message/model/latency/usage/diagnostic persistence and session aggregates;
- provider failure mapping, content-free failure persistence, and conservative reconciliation;
- installation-only global quota buckets and opt-in real-MySQL reconciliation;
- replay/in-progress behavior inherited from reservation tests;
- exact legacy `/api/v1/chat` citation projection and existing controller compatibility suite.

Automated tests use fake providers and make no paid calls.
