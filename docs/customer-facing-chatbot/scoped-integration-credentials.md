# Chatbot API keys (scoped server integration credentials)

Milestone 12 implements ADR-030 as a separate resource. It does not widen `api_keys` and cannot authorize `/api/v1/retrieve`, `/api/v1/chat`, public browser routes, or administrator routes.

The administrator creates keys under **Chatbot API Keys**, chooses one to 100 chatbot scopes, and may set an expiry. “Chatbot API key” is the administrator-facing name; the code and schema retain the precise internal term “integration credential.” The `chatint_live_` secret contains 256 random bits, is shown once, and is stored only as a safe prefix plus SHA-256 hash. Authentication verifies the hash with `hash_equals`, status, expiry, and exact chatbot relation. Revocation is immediate; rotation means creating a replacement and revoking the old key. Deletion cascades only scope relations.

Server routes are:

```text
POST /api/integrations/v1/chatbots/{public_id}/sessions
POST /api/integrations/v1/chatbots/{public_id}/sessions/{session_id}/messages
```

Both require `Authorization: Bearer chatint_live_…`. Session creation accepts exactly `{}` and returns the same one-time hash-only session credential contract as the widget, with channel `integration` and production classification. Message submission additionally requires `X-Chatbot-Session-Token` and exactly `message` plus `idempotency_key`. This preserves two distinct layers: the scoped server credential and the publication-bound session credential.

```bash
curl -X POST https://askasio.example.com/api/integrations/v1/chatbots/cb_example/sessions \
  -H 'Authorization: Bearer chatint_live_replace_me' \
  -H 'Content-Type: application/json' \
  -d '{}'

curl -X POST https://askasio.example.com/api/integrations/v1/chatbots/cb_example/sessions/cs_example/messages \
  -H 'Authorization: Bearer chatint_live_replace_me' \
  -H 'X-Chatbot-Session-Token: cst_v1_replace_me' \
  -H 'Content-Type: application/json' \
  -d '{"message":"What is the refund policy?","idempotency_key":"019f-example"}'
```

The route uses the shared source-scoped execution service, session limits/idempotency/persistence, and installation-wide provider quota gate. Chatbot API key records accumulate content-free API request count, last use, and lifetime AI usage. “AI usage” means embedding, model-input, and model-output tokens; it is not an authentication token or monetary-cost estimate. Dedicated atomic limits default to 120 requests per key and 240 per IP in the shared 60-second window. API Activity remains content-free and does not misclassify the key as a general API key.

Migration `20260721000019` creates credential/scope tables and extends the request-counter check with `integration`. Forward repair is preferred after partial MySQL DDL. A packaged WordPress plugin remains deferred; WordPress can embed the secret-free browser widget today.
