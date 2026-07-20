# Public API and integrations

## API namespace and conventions

Chatbot public endpoints must be versioned separately enough to distinguish them from the existing bearer-authenticated general RAG endpoints while retaining the same JSON/error conventions. Proposed routes:

```text
GET    /api/public/v1/chatbots/{public_chatbot_id}/config
POST   /api/public/v1/chatbots/{public_chatbot_id}/sessions
POST   /api/public/v1/chatbots/{public_chatbot_id}/sessions/{session_id}/messages
DELETE /api/public/v1/chatbots/{public_chatbot_id}/sessions/{session_id}
```

The session ID path shape is illustrative until the session-token design is approved. A combined first-message/session endpoint may be chosen if it improves atomicity without obscuring the contract.

All endpoints must:

- accept/return UTF-8 JSON except the static widget asset;
- enforce request-body and field-specific limits;
- reject unknown top-level fields;
- use server-generated high-entropy public identifiers;
- return `Cache-Control: no-store` for session/message/error responses;
- include `X-Request-ID` and the existing request ID field where applicable;
- return the existing `{ "error": { "code", "message", "request_id" } }` envelope;
- expose no PDO/domain records directly.

The current `/api/v1/chat` and `/api/v1/retrieve` remain authenticated server-to-server APIs. They must not be quietly repurposed as unauthenticated widget endpoints.

## Public configuration

`GET .../config` resolves only a published, enabled chatbot and a permitted origin. It may return:

```json
{
  "chatbot": {
    "id": "cb_public_opaque",
    "configuration_version": 3,
    "display_name": "Support assistant",
    "welcome_message": "How can I help?",
    "suggested_questions": ["What is your refund policy?"],
    "input_placeholder": "Ask a question",
    "appearance": {
      "accent": "#2457d6",
      "theme": "light",
      "position": "right",
      "size": "standard"
    },
    "capabilities": {
      "citations": true,
      "restart": true,
      "streaming": false
    },
    "maximum_message_characters": 4000,
    "privacy_notice_url": "https://example.com/privacy"
  },
  "api_version": "v1",
  "request_id": "019f..."
}
```

Never return provider credentials, provider connection IDs, internal chatbot/source IDs, model pricing, raw system instructions, hidden safety policy, private paths, internal diagnostics, integration secrets, or fields merely because they exist in configuration JSON.

Public configuration may use a short private/shared cache only when keyed by public ID, configuration version, and normalized origin. Responses must vary by `Origin` when origin-specific and must be invalidated on publish, disable, rotation, or deletion.

## Session creation

The server must:

1. Resolve a published, enabled chatbot by public ID without revealing whether a forbidden record exists.
2. Validate the normalized `Origin` for browser requests or integration authentication for trusted server requests.
3. Enforce IP/chatbot creation limits and provider-budget preconditions where relevant.
4. Validate and whitelist bounded metadata.
5. Create a server-owned session tied to exactly one chatbot with idle and absolute expiry.
6. Return a client-safe session identifier plus secret/token design approved before implementation.

A browser cannot supply a trusted external-user ID. An authenticated server integration may supply an allowlisted bounded reference, but Ask Asio treats it as an opaque correlation value, not as proof of identity beyond that integration.

## Sending a message

Example request:

```json
{
  "message": "What is the refund policy?",
  "idempotency_key": "client-generated-high-entropy-value"
}
```

Before provider access, the server validates:

- chatbot remains published/enabled;
- session token is valid, unexpired, and belongs to the path chatbot;
- origin or integration credential still qualifies;
- JSON type/shape and message character limit;
- session message limit and allowed metadata;
- IP, session, chatbot, and credential rate limits;
- global/key provider token budget reservation;
- idempotency key syntax and prior outcome.

The shared execution service then persists/stages the user message according to storage policy, retrieves only assigned sources, applies the relevance threshold, returns fallback without generation when evidence is absent, generates and validates a cited answer otherwise, records bounded diagnostics/usage, and reconciles the quota reservation.

Example response:

```json
{
  "session_id": "cs_public_opaque",
  "message_id": "cm_public_opaque",
  "answer": "Eligible refunds are available within 30 days [S1].",
  "citations": [
    {
      "reference": "S1",
      "title": "Refund policy",
      "heading": "Eligibility",
      "url": "https://example.com/refunds"
    }
  ],
  "usage": {
    "retrieved_chunks": 1
  },
  "request_id": "019f..."
}
```

Public usage must be deliberately minimal; token counts, quota values, cost, similarity scores, internal chunk IDs, and source version IDs belong in admin diagnostics unless a documented client need outweighs disclosure risk.

## Session deletion or restart

`DELETE` must authenticate the session, be idempotent, and stop further messages. **Decision required:** choose whether it immediately hard-deletes stored content, marks the session completed for retention, or provides separate restart and erasure actions. The public UI must not promise legal erasure semantics that backups or configured retention cannot meet.

## Error codes

Stable proposed codes:

| Status | Code | Meaning |
| --- | --- | --- |
| 400 | `invalid_json` / `invalid_request` | Malformed JSON or unsupported shape. |
| 401 | `invalid_session` | Missing or invalid session authorization. |
| 403 | `origin_not_allowed` | Browser origin is not permitted. |
| 404 | `chatbot_not_found` | Public ID is unknown or intentionally concealed. |
| 409 | `message_in_progress` | Same idempotency key is already executing. |
| 410 | `session_expired` | Session can no longer accept messages. |
| 413 | `request_too_large` | Body exceeds the global cap. |
| 415 | `unsupported_media_type` | Expected JSON was not supplied. |
| 422 | `message_too_long` / `invalid_request` | Field validation failed. |
| 429 | `rate_limit_exceeded` / `quota_exceeded` | Request or provider budget limit reached. |
| 503 | `knowledge_unavailable` / safe provider codes | Temporary dependency/configuration failure. |
| 504 | `provider_timeout` | Provider timed out. |

Disabled and unpublished chatbots should normally use `chatbot_not_found` publicly to reduce lifecycle disclosure, while authenticated admin preview receives precise validation.

## Streaming

Streaming is deferred for the initial contract. Non-streaming is mandatory for simple and WordPress integrations. If later approved, use SSE or fetch streaming only after PHP-FPM/Apache buffering, timeouts, cancellation, persistence, quota reconciliation, and retry semantics are proven.

The protocol must define `session`, `response_start`, `text_delta`, `citation`, `response_complete`, and `error` events; never render a partial answer as completed; and prevent duplicate generations after interrupted retries. WebSockets are out of scope unless the application architecture changes materially.

## JavaScript widget

Proposed installation:

```html
<script
  src="https://askasio.example.com/chat-widget/v1.js"
  data-chatbot-id="cb_public_opaque"
  async>
</script>
```

The loader must:

- use only a public chatbot ID;
- fetch client-safe configuration and communicate only with Ask Asio;
- load asynchronously and fail safely;
- isolate styles/DOM using an iframe or Shadow DOM design approved before implementation;
- avoid arbitrary host-page code execution and global namespace pollution;
- support current desktop/mobile browsers with a documented matrix;
- keep a versioned URL and backward-compatible v1 contract;
- render all visitor/model content safely.

Accessibility minimums are semantic controls, visible focus, complete keyboard operation, screen-reader labels, correct dialog name/focus/close behavior, sufficient contrast, reduced motion, live status announcements, and no keyboard trap.

## Browser storage

Store only the session token/ID and minimal UI state. Never store provider/integration credentials, internal prompts, full transcripts, or diagnostics in browser storage. **Decision required:** prefer `sessionStorage` for lower persistence unless product continuity across tabs/restarts is required; document expiry, cross-tab behavior, restart, and privacy impact before widget implementation.

## Origin and CORS behavior

Browser protection uses exact normalized origins (`scheme://host[:port]`), not loose domain suffix matching. For approved origins, echo the exact origin and appropriate `Vary: Origin`; never combine credentialed requests with `Access-Control-Allow-Origin: *`. Define OPTIONS/preflight, allowed methods/headers, local preview, port, scheme, internationalized host, subdomain, trailing-dot, and default-port behavior in tests.

Origin is not a secret and can be forged outside a browser. Public IDs and CORS therefore must be paired with session tokens, rate/quota limits, abuse monitoring, and rotation.

## WordPress and server-to-server integration

WordPress may render the standard widget without storing a secret. Custom server-rendered experiences use a distinct Ask Asio integration credential, never the OpenAI key.

Trusted credential requirements:

- generate high entropy; show plaintext once; store prefix plus deterministic hash;
- support name, expiry, rotation/replacement, revocation, last used, and status;
- scope to explicit chatbot IDs through a relation table;
- authenticate through a documented header over HTTPS;
- apply credential/chatbot/IP/provider limits and safe usage logs;
- never permit admin endpoints, raw retrieval outside assigned chatbot sources, or provider-key recovery.

Existing `rag_live_` keys may be extended only after deciding whether their broad retrieve/chat capability is compatible with chatbot-specific scopes. Creating a separate prefix/type is safer by default because the trust and permissions differ.

