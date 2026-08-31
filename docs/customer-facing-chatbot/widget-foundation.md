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

Each chatbot publication selects one allowlisted widget layout. `floating` preserves the fixed left/right launcher and compact/standard panel. `inline_fullscreen` renders only a full-width question field and submit button at the element named by `data-container-id`; submitting the first question moves the widget to a maximum-priority body layer and opens a modal conversation that covers the viewport while centring transcript and input content within 800 pixels. Closing it restores the widget to its original embed location.

An inline embed provides its mount element before loading the script:

```html
<section id="ask-asio-search"></section>
<script
  src="https://askasio.example.com/chat-widget/v1.js"
  data-chatbot-id="cb_replace_with_public_id"
  data-container-id="ask-asio-search"
  async></script>
```

If the configured container is absent, the loader falls back to the document body. Floating chatbots ignore container placement visually because their host remains fixed. The layout is publication-owned, so installations that require both styles should configure and publish separate chatbots.

For a strict Content Security Policy, allow the Ask Asio origin in `script-src`, `style-src`, and `connect-src`. The embedding page must provide modern browser primitives: Shadow DOM, Fetch, Promises, Web Crypto, `sessionStorage`, and standard DOM APIs. The supported baseline is current evergreen Chrome, Edge, Firefox, and Safari; Internet Explorer is not supported.

## Isolation and accessibility

The loader creates an isolated Shadow DOM rather than an iframe. This preserves the embedding page's browser `Origin` for API authorization while containing widget styles. The external versioned stylesheet provides desktop/mobile sizing, high-contrast behavior, visible keyboard focus, and reduced-motion handling.

The floating launcher exposes expanded/control state and opens a labelled non-modal dialog. The inline prompt is a labelled search region; its fullscreen panel is modal, contains keyboard focus, locks background scrolling, closes with Escape, and restores focus to the inline search input. Both layouts expose a named polite transcript log, labelled messages and controls, Enter submission, Shift+Enter line insertion in the conversation input, restart, busy state, and dynamic-viewport mobile sizing.

## Session and message behavior

Configuration loads immediately. A production session is created lazily on the first message, limiting unused transcript records. The widget stores only `session_id`, the one-time bearer, idle expiry, and absolute expiry in per-tab `sessionStorage`, namespaced by widget version, API origin, and public chatbot ID. It stores no transcript, source content, cookies, visitor metadata, or long-lived local storage. If storage is unavailable, the same values remain memory-only for the page lifetime.

Each submission gets a Web Crypto idempotency value and uses the non-streaming public message route. Restart best-effort completes the current session, clears local credentials/transcript, and returns to the published welcome state; a new session is created lazily on the next message. A `401` or `410` clears unusable local credentials.

The widget shows bounded user-friendly configuration, rate-limit, expiry, session-limit, and generic retry states. It never renders server text as HTML. Message text uses `textContent`; citation and privacy URLs are parsed and limited to HTTP(S), opened with `noopener noreferrer`, and all arbitrary citation fields remain ignored. No token, prompt, internal diagnostic, or provider detail is shown.

## Origin behavior

Cross-origin embeds send `Origin` and receive the exact allowlisted CORS response. A widget hosted and embedded on the same Ask Asio origin may omit `Origin`; requests with the browser signal `Sec-Fetch-Site: same-origin` are authorized against the direct request scheme and `Host`. Missing `Origin` without that signal remains forbidden. Forwarded scheme/host headers are not trusted.

## Compatibility verification

Asset contract tests protect the versioned loader, Shadow DOM boundary, both layout modes, session-only storage, API paths, bearer/idempotency behavior, safe DOM rendering, URL scheme restriction, accessible names/groups/busy state, modal focus/scroll behavior, keyboard hooks, dynamic-viewport responsive layout, forced colors, and reduced motion. HTTP tests separately cover the widget-facing API and same-origin exception. Browser release checks must load both layouts, inspect their rendered/accessibility-tree state, exercise launcher/search/fullscreen/close/keyboard behavior, and test a narrow viewport without making a paid provider call. The required matrix and release threshold are in [Security, accessibility, and end-to-end release gate](security-accessibility-release-gate.md#widget-browser-and-accessibility-matrix).
