# Widget foundation

## Installation

Milestone 10 serves a versioned loader and stylesheet from the public web root:

```html
<script
  src="https://askasio.example.com/chat-widget/v1.js"
  data-chatbot-id="cb_replace_with_public_id"
  async></script>
```

For local development, Ask Asio is served by Laravel Herd at `https://askasio.test`, so external client demos should load `https://askasio.test/chat-widget/v1.js`. The client site's own exact origin must be added to the chatbot draft and published as described below.

Add the embedding page's exact origin to the chatbot draft and publish it before loading the widget. The loader derives the API origin from its own `src`; it does not accept a caller-supplied API URL or credential. Duplicate loaders for the same API origin/public ID collapse to one instance.

For a strict Content Security Policy, allow the Ask Asio origin in `script-src`, `style-src`, and `connect-src`. The embedding page must provide modern browser primitives: Shadow DOM, Fetch, Promises, Web Crypto, `sessionStorage`, and standard DOM APIs. The supported baseline is current evergreen Chrome, Edge, Firefox, and Safari; Internet Explorer is not supported.

## Isolation and accessibility

The loader creates an isolated Shadow DOM rather than an iframe. This preserves the embedding page's browser `Origin` for API authorization while containing widget styles. The external versioned stylesheet provides desktop/mobile sizing, high-contrast behavior, visible keyboard focus, and reduced-motion handling.

The launcher exposes expanded/control state; the panel is a labelled non-modal dialog with busy state; the transcript is a named polite live log; suggestion controls form a named group; messages are labelled by speaker; controls have accessible names; Enter submits, Shift+Enter inserts a line, Escape closes, opening focuses the message input, and closing restores launcher focus. The interface does not trap focus. Mobile sizing uses dynamic viewport units with a `vh` fallback so the panel responds to supported on-screen keyboards.

## Session and message behavior

Configuration loads immediately. A production session is created lazily on the first message, limiting unused transcript records. The widget stores only `session_id`, the one-time bearer, idle expiry, and absolute expiry in per-tab `sessionStorage`, namespaced by widget version, API origin, and public chatbot ID. It stores no transcript, source content, cookies, visitor metadata, or long-lived local storage. If storage is unavailable, the same values remain memory-only for the page lifetime.

Each submission gets a Web Crypto idempotency value and uses the non-streaming public message route. Restart best-effort completes the current session, clears local credentials/transcript, and returns to the published welcome state; a new session is created lazily on the next message. A `401` or `410` clears unusable local credentials.

The widget shows bounded user-friendly configuration, rate-limit, expiry, session-limit, and generic retry states. It never renders server text as HTML. Message text uses `textContent`; citation and privacy URLs are parsed and limited to HTTP(S), opened with `noopener noreferrer`, and all arbitrary citation fields remain ignored. No token, prompt, internal diagnostic, or provider detail is shown.

## Origin behavior

Cross-origin embeds send `Origin` and receive the exact allowlisted CORS response. A widget hosted and embedded on the same Ask Asio origin may omit `Origin`; requests with the browser signal `Sec-Fetch-Site: same-origin` are authorized against the direct request scheme and `Host`. Missing `Origin` without that signal remains forbidden. Forwarded scheme/host headers are not trusted.

## Compatibility verification

Asset contract tests protect the versioned loader, Shadow DOM boundary, session-only storage, API paths, bearer/idempotency behavior, safe DOM rendering, URL scheme restriction, accessible names/groups/busy state, keyboard hooks, focus states, dynamic-viewport responsive layout, forced colors, and reduced motion. HTTP tests separately cover the widget-facing API and same-origin exception. Browser release checks must load the real asset, inspect its rendered/accessibility-tree state, exercise launcher/close/keyboard behavior, and test a narrow viewport without making a paid provider call. The required matrix and release threshold are in [Security, accessibility, and end-to-end release gate](security-accessibility-release-gate.md#widget-browser-and-accessibility-matrix).
