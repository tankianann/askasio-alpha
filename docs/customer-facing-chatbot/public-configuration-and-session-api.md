# Public configuration and session API

## Implemented scope

Milestone 8 introduced these browser-facing routes:

```text
GET     /api/public/v1/chatbots/{public_chatbot_id}/config
OPTIONS /api/public/v1/chatbots/{public_chatbot_id}/config
POST    /api/public/v1/chatbots/{public_chatbot_id}/sessions
OPTIONS /api/public/v1/chatbots/{public_chatbot_id}/sessions
```

They resolve only an active chatbot's immutable active publication. Milestones 9 and 10 add authorized messages, completion-backed widget restart, and widget assets; milestone 12 adds separate server integration credentials outside this browser namespace. Streaming and public transcript retrieval/deletion remain unavailable.

## Origin and CORS policy

Cross-origin requests require `Origin`. Same-origin widget requests may omit it only when `Sec-Fetch-Site: same-origin`, in which case the direct scheme and `Host` form the checked origin. The shared normalizer canonicalizes HTTP(S) scheme, case, IDN host where supported, trailing dot, IPv6 brackets, and default ports. Non-local HTTP, credentials, queries, fragments, malformed hosts, wildcard/suffix/subdomain guesses, and unlisted non-default ports are rejected. Authorization compares the normalized value with the publication's exact immutable origin list.

Allowed responses echo the request origin rather than `*`, return `Vary: Origin`, `Cache-Control: no-store`, and `Cross-Origin-Resource-Policy: cross-origin`. Cookies are unused and `Access-Control-Allow-Credentials` is omitted. Forbidden origins receive no allow-origin header. Preflight permits only the route's method; session creation permits only `Content-Type`. Preflight is content-free, not rate-counted, and cached for at most 600 seconds.

CORS is browser policy, not authentication. A non-browser client can forge `Origin`; high-entropy IDs, hash-only session tokens, rate limits, publication checks, and later message authorization remain independent controls.

## Public configuration contract

The response contains `chatbot`, `api_version`, and `request_id`. The allowlisted chatbot DTO contains only public ID, publication number, display/welcome/starter/placeholder presentation, `floating|inline_fullscreen` layout, accent/theme/position/launcher/panel-title/size appearance, citation/restart/streaming capabilities, maximum message characters, privacy URL, and disclosure text. Publications created before the layout field existed project safely as `floating`.

It never serializes domain/PDO records, internal/source IDs, assignments, origins, instructions, provider/model metadata, credentials, quota state, diagnostics, or arbitrary JSON keys. Disabled, archived, unpublished, malformed, and unknown IDs use `404 chatbot_not_found`. A stale/unconfigured installation provider returns safe `503 knowledge_unavailable` after origin authorization.

## Session creation contract

Session creation requires `Content-Type: application/json` and exactly `{}`. Unknown fields, browser-supplied identity/metadata, arrays, malformed JSON, wrong media types, and oversized bodies use the standard safe error envelope. Metadata remains unsupported until a normalized storage/privacy contract is approved.

The repository transaction revalidates status, publication, and origin. The session is production traffic (`channel = browser`, `is_test = 0`) and copies the publication's limits, expiry, and retention. The `201` response returns the session ID, one-time token, idle/absolute UTC expiry, and request ID.

The independent ID and token each contain 256 random bits. Only the token's safe prefix and SHA-256 hash are stored. Plaintext never enters a URL, cookie, Activity record, or log. The v1 widget stores it in per-tab `sessionStorage`; see [Widget foundation](widget-foundation.md).

## Rate limits and activity

Configuration and creation use independent fixed-window namespaces with atomic HMAC-derived IP and public-chatbot counters:

| Endpoint | IP/window | Chatbot/window |
| --- | ---: | ---: |
| Configuration | 120 | 600 |
| Session creation | 20 | 120 |

The window defaults to 60 seconds. Rejections return `429 rate_limit_exceeded`, `Retry-After`, limit/remaining headers, and readable CORS only for an approved origin. Migration `20260721000017` expands the existing rate-bucket scope check with `chatbot`; no tenant/account/session/provider-credential table is added.

Authorized public requests reuse privacy-minimized API Activity with the **Browser chatbot** access method. It records request ID, chatbot correlation, HMAC IP, path, method, status, duration, and safe error category—not bodies or tokens. This origin/session authorization is deliberately distinguished from an unauthenticated or failed request even though it does not use a General or Chatbot API key.

## Deployment and forward repair

Deploy code, configuration, and migration `20260721000017` together. Add the five `CHATBOT_PUBLIC_*` variables or accept documented defaults, then migrate before enabling browser traffic. No backfill is required. The down path deletes ephemeral chatbot counters before restoring the old check; production rollback should prefer forward repair after inspecting actual constraints because MySQL DDL may auto-commit.

Reliable IP limits behind a TLS-terminating proxy still depend on the planned trusted-proxy policy. Do not trust forwarded headers ad hoc.

## Verification

HTTP/security tests cover DTO allowlisting, internal-field absence, normalized/exact origins, missing/opaque/HTTP/subdomain/suffix/port rejection, CORS errors, preflight method/header restrictions, lifecycle concealment, stale-provider handling, strict JSON/media/body schemas, one-time token/hash persistence, production classification, IP/chatbot limits, request IDs, no-store, and cross-origin resource policy.
