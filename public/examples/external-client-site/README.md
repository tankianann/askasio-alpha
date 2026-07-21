# External client site demo

This dependency-free demo presents the AskAsio v1 chat widget on a fictional customer website, served from a different browser origin than AskAsio. The host page does not contain an API client, chat UI, provider key, integration credential, or session token; it loads the existing versioned widget asset.

## Configure the widget

Open `index.html` and update the script element at the end of the file:

```html
<script
    src="https://askasio.test/chat-widget/v1.js"
    data-chatbot-id="cb_REPLACE_WITH_PUBLIC_ID"
    async></script>
```

1. Replace `https://askasio.test` if AskAsio is available at another origin. Keep `/chat-widget/v1.js` on the end.
2. Replace `cb_REPLACE_WITH_PUBLIC_ID` with the **Public ID** shown near the top of the chatbot edit screen in AskAsio.

The widget intentionally derives its API origin from the script `src`. There is no separate API URL setting, and the public chatbot ID is an identifier rather than a secret. Do not add provider keys, integration credentials, or session tokens to this demo.

Local URL examples:

- Laravel Herd (the repository default): `https://askasio.test/chat-widget/v1.js`
- PHP development server: `http://127.0.0.1:8080/chat-widget/v1.js`

If using the PHP server URL, update the script `src` accordingly. An HTTPS client page cannot load an HTTP widget script because browsers block mixed content.

## Run AskAsio and the demo on separate origins

The recommended client origin is exactly:

```text
http://127.0.0.1:4173
```

With AskAsio already configured, migrated, and connected to its database, run these in separate terminals from the repository root.

### Option A: AskAsio through Laravel Herd

```bash
herd start
php -S 127.0.0.1:4173 -t public
```

Keep the default `https://askasio.test/chat-widget/v1.js` script URL, then open <http://127.0.0.1:4173/examples/external-client-site/>.

### Option B: two PHP development servers

Terminal 1:

```bash
php -S 127.0.0.1:8080 -t public public/index.php
```

Terminal 2:

```bash
php -S 127.0.0.1:4173 -t public
```

For this option, change the widget script URL in `index.html` to `http://127.0.0.1:8080/chat-widget/v1.js`, then open <http://127.0.0.1:4173/examples/external-client-site/>.

The demo files live at the requested repository path under `public/examples`. The second server exposes them on a different port, so the browser sees a genuinely separate `http://127.0.0.1:4173` customer origin; do not test the demo through the AskAsio origin.

## Allow the client origin and publish

1. Sign in to AskAsio and open **Chatbots**.
2. Open the chatbot used by the demo.
3. In **Origins**, add this exact origin on its own line:

   ```text
   http://127.0.0.1:4173
   ```

   Do not include a path, trailing slash, wildcard, query string, or fragment. `localhost`, `127.0.0.1`, HTTP, HTTPS, and different ports are different origins.
4. Select **Save origins**.
5. Select **Publish current draft**. Saving a draft does not update the active public configuration.
6. Confirm the chatbot has an active publication and is enabled.

Any later origin, source, behavior, appearance, or model-related configuration change must be reviewed and republished before the widget uses it.

## Verification checklist

Use a current Chrome, Edge, Firefox, or Safari browser. Open the demo and its developer tools.

### Configuration and CORS

- In **Network**, confirm `v1.js` and `v1.css` load from the AskAsio origin, not from port 4173.
- Confirm the widget requests `GET /api/public/v1/chatbots/{public_id}/config` immediately and receives `200`.
- Inspect the response headers. For this demo, `Access-Control-Allow-Origin` must be exactly `http://127.0.0.1:4173`, and the response should vary by `Origin`.
- Open the launcher and confirm the published title, welcome text, suggested questions, colors, position, and privacy link appear.

### Session creation and messaging

- Reload the page and do not send a message. Configuration should load, but no production session should be created.
- Send a suggested question or a typed question. Confirm a `POST .../sessions` request is made first, followed by `POST .../sessions/{session_id}/messages`.
- Confirm the message request carries an `Authorization: Bearer ...` header and a JSON idempotency key. Never copy the bearer into source code, logs, or bug reports.
- Confirm the assistant answer appears as text. Ask a question covered by an assigned source and confirm published citations appear as safe source links when citation display is enabled.

### Restart

- Use the widget's **Restart conversation** control.
- If a session exists, confirm a best-effort `POST .../complete` returns `204`.
- Confirm the transcript returns to the published welcome state and the stored session entry is removed.
- Send another message and confirm a new session is created lazily.

### `sessionStorage`

- In **Application/Storage → Session Storage**, inspect the entry beginning with `askasio:chat:v1:`. It should contain only `session_id`, `session_token`, `idle_expires_at`, and `absolute_expires_at`.
- Reload in the same tab: the valid session credentials should be reused, while the transcript itself is not stored.
- Open the demo in a separate tab: it should have independent per-tab storage and create its own session after the first message.
- Clear session storage or close the tab. The next message in a fresh tab should create a new session.
- Confirm there is no AskAsio chat state in cookies or `localStorage`.

### Negative CORS check

Optionally serve the same directory at an origin that is not allowlisted:

```bash
php -S 127.0.0.1:4174 -t public
```

Open `http://127.0.0.1:4174/examples/external-client-site/`. Configuration should be rejected because the port makes it a different exact origin. Stop this temporary server after the check.

## Troubleshooting

### `origin_not_allowed` or a browser CORS error

- Compare the browser page's origin exactly with the active chatbot publication: scheme, hostname, and port must all match.
- Add `http://127.0.0.1:4173`, not a URL with `/`, a page path, or a wildcard.
- Save the Origins draft and then select **Publish current draft**.
- Confirm the page was opened on port 4173 rather than directly from disk (`file:`), through AskAsio, on `localhost`, or on another port.
- If the host page has a Content Security Policy, allow the AskAsio origin in `script-src`, `style-src`, and `connect-src`.

### `chatbot_not_found`

- Replace `cb_REPLACE_WITH_PUBLIC_ID` with the chatbot's current Public ID and check for missing characters.
- Confirm the chatbot is published and enabled. Disabled and unpublished chatbots intentionally look not found to public clients.
- If the public ID was rotated, update the embed to the new ID; the previous ID stops working immediately.
- Confirm the script is loading from the AskAsio installation where that chatbot exists.

### Mixed-content blocking

- An HTTPS customer page cannot load `http://127.0.0.1:8080/chat-widget/v1.js`.
- Use AskAsio over HTTPS (the Herd default is `https://askasio.test`) or serve both applications over HTTP for local-only testing.
- If the local AskAsio TLS certificate is not trusted, visit `https://askasio.test` directly and resolve the certificate warning before reloading the demo.

### Stale publication or old settings

- Saving a chatbot only changes its draft. Select **Publish current draft** after reviewing the change.
- Reload the demo after publishing and confirm the configuration request returns the expected publication version.
- If the installation chat model, embedding model, or embedding dimensions changed, ensure assigned sources are ready and compatible, review the chatbot, and republish it deliberately.
- If publication reports that the draft changed, reload the chatbot edit screen, review the newest draft revision, and publish again.
- If a source is still processing or incompatible, finish ingestion/re-embedding before republishing.

## Security notes

- The browser embed uses only the public chatbot ID.
- Session credentials are generated by AskAsio and stored by the widget at runtime in per-tab `sessionStorage`.
- Never put `OPENAI_API_KEY`, `rag_live_...` API keys, chatbot integration credentials, administrator cookies, or captured session credentials into these files.
