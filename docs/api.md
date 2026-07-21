# API reference

## Conventions

- Base paths: `/api/v1` for authenticated general RAG and `/api/public/v1` for the browser chatbot contract.
- Format: JSON for every API endpoint and API error.
- Character encoding: UTF-8.
- Request ID: every request receives a UUIDv7 exposed as `X-Request-ID` and in JSON responses/errors where applicable.
- Paid endpoints require `Authorization: Bearer rag_live_…`.
- `GET /api/v1/health` is public.
- General `/api/v1` routes have no CORS policy. Implemented chatbot-public routes use their publication's exact origin allowlist.
- There is no content negotiation or alternate API version yet.

## Authentication

Create API keys through **Admin → API Access**. A key is shown in full once. The database stores only a safe visible prefix and SHA-256 hash. Active, unexpired, non-revoked keys are accepted.

```http
Authorization: Bearer rag_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Missing, malformed, unknown, revoked, or expired credentials return:

```json
{
  "error": {
    "code": "unauthorized",
    "message": "A valid bearer API key is required.",
    "request_id": "019f..."
  }
}
```

Status is `401`; the response includes `WWW-Authenticate: Bearer realm="Ask Asio"` and `Cache-Control: no-store`.

## Error envelope

```json
{
  "error": {
    "code": "invalid_request",
    "message": "The question field is required.",
    "request_id": "019f..."
  }
}
```

| Status | Common code | Meaning |
| --- | --- | --- |
| 400 | `invalid_json` | Malformed JSON. |
| 400 | `invalid_request` | Top-level body is not a JSON object. |
| 401 | `unauthorized` | Bearer key is missing or unusable. |
| 404 | `not_found` or router-specific safe code | Route does not exist. |
| 413 | `request_too_large` | Body exceeds `API_MAXIMUM_BODY_BYTES`. |
| 415 | `unsupported_media_type` | Content type is not `application/json`. |
| 422 | `invalid_request` | Field/schema/range validation failed. |
| 429 | `rate_limit_exceeded` | Fixed-window request limit exceeded. |
| 429 | `quota_exceeded` | Global or per-key provider token budget cannot reserve the request. |
| 500 | `internal_error` | Unexpected failure; use request ID for logs. |
| 502 | `provider_invalid_response`, `provider_error` | Chat provider returned malformed output or failed. |
| 503 | `provider_configuration_error`, `provider_authentication_error`, `provider_rate_limited` | Chat provider configuration/credentials/rate availability problem. |
| 504 | `provider_timeout` | Chat embedding/generation timed out. |

Provider errors are deliberately generic and never include credentials, provider headers, prompts, or stack traces. Retrieval currently maps post-reservation provider failures to the generic error boundary rather than the richer chat provider codes; this inconsistency is tracked in [Roadmap](roadmap.md#future-improvements).

## Rate limiting and quotas

Retrieval defaults to 60 requests/key and 120 requests/IP per 60 seconds. Chat uses an independent namespace with defaults of 10/key and 20/IP per 60 seconds. The effective lower remaining count is returned:

```http
X-RateLimit-Limit: 10
X-RateLimit-Remaining: 9
```

A rate rejection includes those headers, `Retry-After`, and `Cache-Control: no-store`.

Public configuration, session creation, and message submission use separate 60-second namespaces. Defaults are respectively 120/IP and 600/chatbot; 20/IP and 120/chatbot; then 30/IP, 300/chatbot, and 20/session. Identifiers are HMAC-derived and counters are atomic in MySQL.

Separate global/per-key UTC daily/monthly provider-token budgets reserve conservatively before an embedding/chat call. An insufficient budget returns `quota_exceeded` before any provider call. Successful usage can include:

```json
{
  "provider_total_tokens": 164,
  "quota_charged_tokens": 164,
  "quota_reserved_tokens": 18704,
  "quota_daily_remaining_tokens": 99836,
  "quota_monthly_remaining_tokens": 999836
}
```

Remaining fields are omitted when all applicable limits are configured as unlimited (`0`). `quota_reserved_tokens` describes the conservative preflight allowance, not the final charge.

## Public chatbot API

Implemented browser routes:

```text
GET     /api/public/v1/chatbots/{cb_…}/config
OPTIONS /api/public/v1/chatbots/{cb_…}/config
POST    /api/public/v1/chatbots/{cb_…}/sessions
OPTIONS /api/public/v1/chatbots/{cb_…}/sessions
POST    /api/public/v1/chatbots/{cb_…}/sessions/{cs_…}/messages
OPTIONS /api/public/v1/chatbots/{cb_…}/sessions/{cs_…}/messages
POST    /api/public/v1/chatbots/{cb_…}/sessions/{cs_…}/complete
OPTIONS /api/public/v1/chatbots/{cb_…}/sessions/{cs_…}/complete
```

All require an exact published allowlisted `Origin`. Allowed responses echo that origin, never `*`, and return `Vary: Origin` plus `Cache-Control: no-store`. Configuration exposes only the versioned public presentation/capability/privacy DTO; it excludes instructions, models, credentials, source/internal IDs, origins, and diagnostics.

Session creation requires `Content-Type: application/json` with exactly `{}` and returns `201` with `session_id`, one-time `session_token`, idle/absolute UTC expiry, and `request_id`. The token contains 256 random bits and only its SHA-256 hash/safe prefix are stored.

Messages require that token as a Bearer credential and exactly:

```json
{
  "message": "What is the refund policy?",
  "idempotency_key": "019f..."
}
```

A successful response contains the session/message public IDs, grounded `answer`, allowlisted public `citations`, `usage.retrieved_chunks`, `fallback`, `replayed`, and `request_id`. Completion requires the same bearer and exactly `{}`, returns `204`, and is the widget's restart primitive. Public transcript retrieval and deletion are not implemented. See [Public configuration/session](customer-facing-chatbot/public-configuration-and-session-api.md), [Public messages](customer-facing-chatbot/public-message-api.md), and [Widget foundation](customer-facing-chatbot/widget-foundation.md).

## `GET /api/v1/health`

Public liveness/readiness check for the application and database.

Success (`200`):

```json
{
  "status": "ok",
  "services": {
    "application": "ok",
    "database": "ok"
  },
  "timestamp": "2026-07-20T03:06:37+00:00",
  "request_id": "019f..."
}
```

Database failure returns `503`, top-level status `degraded`, and database `unavailable`. It never exposes database host, credentials, SQL errors, or exception details.

## `POST /api/v1/retrieve`

Embeds one complete query, searches active compatible chunks locally, and returns matches. The query is not split into chunks.

### Request

```json
{
  "query": "refund conditions",
  "top_k": 10,
  "filters": {
    "source_ids": [1, 4],
    "source_types": ["url", "pdf"]
  }
}
```

| Field | Required | Validation |
| --- | --- | --- |
| `query` | yes | Non-empty string after trim; at most `RAG_RETRIEVAL_MAXIMUM_QUERY_CHARACTERS` (default 4,000). |
| `top_k` | no | Integer 1 through `RAG_RETRIEVAL_MAXIMUM_TOP_K` (default maximum 20); server default is 8. |
| `filters` | no | JSON object; only `source_ids` and `source_types`. |
| `filters.source_ids` | no | Non-empty array of positive integers. |
| `filters.source_types` | no | Non-empty array containing `url`, `markdown`, and/or `pdf`. |

Unknown filter keys are rejected. Unknown top-level fields are currently ignored, unlike chat; clients should not rely on that behavior.

### Response

```json
{
  "matches": [
    {
      "source_id": 1,
      "source_version_id": 3,
      "chunk_id": 18,
      "chunk_number": 2,
      "similarity": 0.876543,
      "content": "Refunds are available within 30 days...",
      "source_name": "Refund Policy",
      "source_type": "url",
      "source_url": "https://example.com/refunds",
      "page": null,
      "heading": "Eligibility",
      "metadata": {
        "section_title": "Eligibility"
      }
    }
  ],
  "usage": {
    "retrieved_chunks": 1,
    "embedding_tokens": 5,
    "provider_total_tokens": 5,
    "quota_charged_tokens": 5,
    "quota_reserved_tokens": 17,
    "quota_daily_remaining_tokens": 99995,
    "quota_monthly_remaining_tokens": 999995
  },
  "request_id": "019f..."
}
```

Matches are sorted by descending cosine similarity with chunk ID as a deterministic tie-breaker. Only enabled, non-deleted sources whose active version is `ready` and whose embeddings match the configured model/dimensions are eligible.

### Example

```bash
curl -X POST https://ragserver.example.com/api/v1/retrieve \
  -H 'Authorization: Bearer rag_live_replace_with_your_key' \
  -H 'Content-Type: application/json' \
  -d '{"query":"refund conditions","top_k":10}'
```

## `POST /api/v1/chat`

Runs retrieval, selects token-bounded context, and asks the configured chat model for a grounded answer using the OpenAI Responses API.

### Request

```json
{
  "question": "What is the refund policy?",
  "conversation_id": null,
  "top_k": 5
}
```

| Field | Required | Validation |
| --- | --- | --- |
| `question` | yes | Non-empty string after trim; at most `RAG_CHAT_MAXIMUM_QUESTION_CHARACTERS` (default 4,000). |
| `conversation_id` | no | Must be omitted or `null`; conversations are not implemented. |
| `top_k` | no | Integer 1 through `RAG_CHAT_MAXIMUM_TOP_K` (default maximum 8); server default is 5. |

Any unknown top-level field returns `422 invalid_request`.

### Response

```json
{
  "answer": "The source states that eligible refunds are available within 30 days [S1].",
  "citations": [
    {
      "reference": "S1",
      "source_id": 1,
      "source_version_id": 3,
      "chunk_id": 18,
      "source_name": "Refund Policy",
      "source_type": "url",
      "source_url": "https://example.com/refunds",
      "page": null,
      "heading": "Eligibility",
      "excerpt": "Refunds are available within 30 days..."
    }
  ],
  "usage": {
    "retrieved_chunks": 1,
    "context_tokens": 120,
    "input_tokens": 310,
    "cached_input_tokens": 0,
    "output_tokens": 42,
    "reasoning_tokens": 0,
    "total_tokens": 352,
    "embedding_tokens": 7,
    "provider_total_tokens": 359,
    "quota_charged_tokens": 359,
    "quota_reserved_tokens": 18704,
    "quota_daily_remaining_tokens": 99641,
    "quota_monthly_remaining_tokens": 999641
  },
  "request_id": "019f..."
}
```

Citation excerpts are whitespace-normalized and limited to 280 characters. The answer must cite valid `[S#]` references. If retrieval provides no qualifying context, Ask Asio returns:

```text
The provided sources do not contain enough information to answer this question.
```

In that case it still incurred the query embedding but makes no chat-generation call.

### Example

```bash
curl -X POST https://ragserver.example.com/api/v1/chat \
  -H 'Authorization: Bearer rag_live_replace_with_your_key' \
  -H 'Content-Type: application/json' \
  -d '{"question":"What is the refund policy?","conversation_id":null,"top_k":5}'
```

## API Activity privacy contract

The application records request ID, numeric API-key ID, HMAC-derived IP identifier, method, path-only endpoint, status, duration, error category, numeric usage, and UTC time. It does not record authorization headers, complete keys, raw IPs, query strings, bodies, questions, retrieved chunks, citations, or answers.

## Compatibility and versioning policy

All public endpoints are under `/api/v1`. Backward-compatible fields may be added. Removing/renaming fields, changing meanings, or tightening accepted values in a client-breaking way requires `/api/v2` or an explicitly announced compatibility plan. Error messages are human-facing and may be refined; clients should branch on HTTP status and `error.code`.
