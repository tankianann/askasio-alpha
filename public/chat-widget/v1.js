(function () {
    'use strict';

    var script = document.currentScript;

    if (!script || !script.src || script.dataset.askasioLoaded === 'true') {
        return;
    }

    script.dataset.askasioLoaded = 'true';

    var chatbotId = (script.dataset.chatbotId || '').trim();
    var apiOrigin;

    try {
        apiOrigin = new URL(script.src, document.baseURI).origin;
    } catch (error) {
        return;
    }

    if (!/^cb_[A-Za-z0-9_-]{20,80}$/.test(chatbotId)) {
        return;
    }

    var instanceKey = apiOrigin + ':' + chatbotId;
    var instances = window.__askasioChatWidgetInstances || (window.__askasioChatWidgetInstances = {});

    if (instances[instanceKey]) {
        return;
    }

    var apiBase = apiOrigin + '/api/public/v1/chatbots/' + encodeURIComponent(chatbotId);
    var storageKey = 'askasio:chat:v1:' + instanceKey;
    var sessionMemory = null;
    var configuration = null;
    var pendingSubmission = null;
    var busy = false;
    var opened = false;

    var host = document.createElement('div');
    host.setAttribute('data-askasio-chat-widget', 'v1');
    var shadow = host.attachShadow({ mode: 'open' });
    var stylesheet = document.createElement('link');
    stylesheet.rel = 'stylesheet';
    stylesheet.href = new URL('./v1.css', script.src).href;
    shadow.appendChild(stylesheet);

    var root = element('div', 'askasio-widget');
    var launcher = element('button', 'askasio-launcher');
    launcher.type = 'button';
    launcher.setAttribute('aria-expanded', 'false');
    launcher.setAttribute('aria-controls', uniqueId('panel'));
    launcher.textContent = 'Chat';

    var panel = element('section', 'askasio-panel');
    panel.id = launcher.getAttribute('aria-controls');
    panel.hidden = true;
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'false');
    panel.setAttribute('aria-labelledby', uniqueId('title'));

    var header = element('header', 'askasio-header');
    var title = element('h2', 'askasio-title');
    title.id = panel.getAttribute('aria-labelledby');
    title.textContent = 'Chat';
    var headerActions = element('div', 'askasio-header-actions');
    var restart = iconButton('Restart conversation', '\u21bb');
    var close = iconButton('Close chat', '\u00d7');
    headerActions.append(restart, close);
    header.append(title, headerActions);

    var transcript = element('div', 'askasio-transcript');
    transcript.setAttribute('role', 'log');
    transcript.setAttribute('aria-live', 'polite');
    transcript.setAttribute('aria-relevant', 'additions text');

    var suggestions = element('div', 'askasio-suggestions');
    suggestions.setAttribute('aria-label', 'Suggested questions');
    var status = element('p', 'askasio-status');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');

    var form = element('form', 'askasio-form');
    var inputLabel = element('label', 'askasio-visually-hidden');
    var inputId = uniqueId('message');
    inputLabel.htmlFor = inputId;
    inputLabel.textContent = 'Message';
    var inputRow = element('div', 'askasio-input-row');
    var input = document.createElement('textarea');
    input.id = inputId;
    input.rows = 1;
    input.required = true;
    input.placeholder = 'Type your question';
    input.setAttribute('aria-describedby', uniqueId('disclosure'));
    var send = element('button', 'askasio-send');
    send.type = 'submit';
    send.textContent = 'Send';
    inputRow.append(input, send);
    form.append(inputLabel, inputRow);

    var disclosure = element('p', 'askasio-disclosure');
    disclosure.id = input.getAttribute('aria-describedby');
    disclosure.textContent = 'Automated assistant. Check important information.';
    var privacy = element('a', 'askasio-privacy');
    privacy.target = '_blank';
    privacy.rel = 'noopener noreferrer';
    privacy.textContent = 'Privacy';
    privacy.hidden = true;

    panel.append(header, transcript, suggestions, status, form, disclosure, privacy);
    root.append(panel, launcher);
    shadow.appendChild(root);
    (document.body || document.documentElement).appendChild(host);
    instances[instanceKey] = host;

    launcher.addEventListener('click', function () {
        setOpen(!opened);
    });
    close.addEventListener('click', function () {
        setOpen(false);
    });
    restart.addEventListener('click', restartConversation);
    form.addEventListener('submit', submitMessage);
    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            form.requestSubmit();
        }
    });
    shadow.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && opened) {
            event.preventDefault();
            setOpen(false);
        }
    });

    setBusy(true, 'Loading chat…');
    request(apiBase + '/config', { method: 'GET' })
        .then(function (payload) {
            configuration = payload.chatbot;
            applyConfiguration(configuration);
            setBusy(false, '');
        })
        .catch(function () {
            setBusy(false, 'Chat is unavailable right now. Please try again later.', true);
            input.disabled = true;
            send.disabled = true;
        });

    function applyConfiguration(config) {
        if (!config || typeof config !== 'object') {
            throw new Error('Invalid chatbot configuration.');
        }

        title.textContent = stringValue(config.appearance && config.appearance.panel_title, stringValue(config.display_name, 'Chat'));
        var launcherLabel = stringValue(config.appearance && config.appearance.launcher_label, 'Chat');
        var launcherIcon = element('span', 'askasio-launcher-icon');
        launcherIcon.setAttribute('aria-hidden', 'true');
        launcherIcon.textContent = iconSymbol(config.appearance && config.appearance.launcher_icon);
        var launcherText = element('span', 'askasio-launcher-text');
        launcherText.textContent = launcherLabel;
        launcher.replaceChildren(launcherIcon, launcherText);
        launcher.setAttribute('aria-label', launcherLabel);
        input.placeholder = stringValue(config.input_placeholder, 'Type your question');
        input.maxLength = positiveInteger(config.maximum_message_characters, 4000);
        disclosure.textContent = stringValue(config.disclosure_text, 'Automated assistant. Check important information.');

        var appearance = config.appearance || {};
        host.dataset.position = appearance.position === 'left' ? 'left' : 'right';
        host.dataset.size = appearance.size === 'compact' ? 'compact' : 'standard';
        host.dataset.theme = appearance.theme === 'dark' ? 'dark' : 'light';
        if (/^#[0-9a-fA-F]{6}$/.test(appearance.accent || '')) {
            host.style.setProperty('--askasio-accent', appearance.accent);
        }

        addMessage('assistant', stringValue(config.welcome_message, 'How can I help?'));
        renderSuggestions(Array.isArray(config.suggested_questions) ? config.suggested_questions : []);

        var privacyUrl = safeHttpUrl(config.privacy_notice_url);
        if (privacyUrl) {
            privacy.href = privacyUrl;
            privacy.hidden = false;
        }
    }

    function renderSuggestions(items) {
        suggestions.replaceChildren();
        items.slice(0, 6).forEach(function (question) {
            if (typeof question !== 'string' || !question.trim()) {
                return;
            }
            var button = element('button', 'askasio-suggestion');
            button.type = 'button';
            button.textContent = question;
            button.addEventListener('click', function () {
                input.value = question;
                form.requestSubmit();
            });
            suggestions.appendChild(button);
        });
    }

    function submitMessage(event) {
        event.preventDefault();
        var message = input.value.trim();

        if (busy || !configuration || !message) {
            return;
        }

        if (message.length > input.maxLength) {
            setStatus('Your message is too long.', true);
            return;
        }

        var retrying = Boolean(pendingSubmission && pendingSubmission.message === message);
        var idempotencyKey = retrying
            ? pendingSubmission.idempotencyKey
            : randomId();
        pendingSubmission = { message: message, idempotencyKey: idempotencyKey };
        input.value = '';
        suggestions.replaceChildren();
        if (!retrying) {
            addMessage('user', message);
        }
        setBusy(true, 'Sending…');

        ensureSession()
            .then(function (session) {
                return request(apiBase + '/sessions/' + encodeURIComponent(session.session_id) + '/messages', {
                    method: 'POST',
                    token: session.session_token,
                    body: { message: message, idempotency_key: idempotencyKey }
                });
            })
            .then(function (payload) {
                addMessage('assistant', stringValue(payload.answer, 'I could not answer that question.'), payload.citations);
                pendingSubmission = null;
                setBusy(false, '');
            })
            .catch(function (error) {
                if (error.code === 'invalid_session' || error.code === 'session_expired') {
                    clearSession();
                }
                if (error.code === 'stale_message_recovered' || error.code === 'idempotency_conflict') {
                    pendingSubmission = null;
                }
                input.value = message;
                setBusy(false, friendlyError(error), true);
            });
    }

    function ensureSession() {
        var existing = readSession();
        if (existing) {
            return Promise.resolve(existing);
        }

        return request(apiBase + '/sessions', { method: 'POST', body: {} }).then(function (session) {
            if (!validSession(session)) {
                throw new Error('Invalid session response.');
            }
            writeSession(session);
            return session;
        });
    }

    function restartConversation() {
        if (busy) {
            return;
        }

        var existing = readSession();
        setBusy(true, 'Restarting…');
        var completion = existing
            ? request(apiBase + '/sessions/' + encodeURIComponent(existing.session_id) + '/complete', {
                method: 'POST', token: existing.session_token, body: {}
            }).catch(function () { return null; })
            : Promise.resolve();

        completion.then(function () {
            clearSession();
            pendingSubmission = null;
            transcript.replaceChildren();
            if (configuration) {
                addMessage('assistant', stringValue(configuration.welcome_message, 'How can I help?'));
                renderSuggestions(Array.isArray(configuration.suggested_questions) ? configuration.suggested_questions : []);
            }
            setBusy(false, 'Conversation restarted.');
            input.focus();
        });
    }

    function request(url, options) {
        var headers = { Accept: 'application/json' };
        if (options.body !== undefined) {
            headers['Content-Type'] = 'application/json';
        }
        if (options.token) {
            headers.Authorization = 'Bearer ' + options.token;
        }

        return fetch(url, {
            method: options.method,
            headers: headers,
            body: options.body === undefined ? undefined : JSON.stringify(options.body),
            credentials: 'omit',
            mode: 'cors',
            referrerPolicy: 'strict-origin-when-cross-origin'
        }).then(function (response) {
            if (response.status === 204) {
                return null;
            }
            return response.json().catch(function () { return {}; }).then(function (payload) {
                if (!response.ok) {
                    var failure = new Error('Chat request failed.');
                    failure.code = payload && payload.error && payload.error.code;
                    failure.status = response.status;
                    throw failure;
                }
                return payload;
            });
        });
    }

    function addMessage(role, text, citations) {
        var item = element('article', 'askasio-message askasio-message-' + role);
        var body = element('p', 'askasio-message-body');
        body.textContent = text;
        item.appendChild(body);

        if (Array.isArray(citations) && citations.length) {
            var list = element('ol', 'askasio-citations');
            list.setAttribute('aria-label', 'Sources');
            citations.forEach(function (citation) {
                if (!citation || typeof citation !== 'object') {
                    return;
                }
                var row = document.createElement('li');
                var label = stringValue(citation.title, stringValue(citation.reference, 'Source'));
                var href = safeHttpUrl(citation.url);
                if (href) {
                    var link = document.createElement('a');
                    link.href = href;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    link.textContent = label;
                    row.appendChild(link);
                } else {
                    row.textContent = label;
                }
                list.appendChild(row);
            });
            if (list.childElementCount) {
                item.appendChild(list);
            }
        }

        transcript.appendChild(item);
        transcript.scrollTop = transcript.scrollHeight;
    }

    function setOpen(next) {
        opened = next;
        panel.hidden = !opened;
        launcher.setAttribute('aria-expanded', opened ? 'true' : 'false');
        if (opened) {
            input.focus();
        } else {
            launcher.focus();
        }
    }

    function setBusy(next, message, isError) {
        busy = next;
        input.disabled = next;
        send.disabled = next;
        restart.disabled = next;
        setStatus(message, isError);
    }

    function setStatus(message, isError) {
        status.textContent = message || '';
        status.dataset.error = isError ? 'true' : 'false';
    }

    function readSession() {
        var candidate = sessionMemory;
        try {
            candidate = JSON.parse(window.sessionStorage.getItem(storageKey) || 'null');
        } catch (error) {
            candidate = sessionMemory;
        }

        if (!validSession(candidate) || Date.parse(candidate.absolute_expires_at) <= Date.now()) {
            clearSession();
            return null;
        }
        return candidate;
    }

    function writeSession(session) {
        sessionMemory = {
            session_id: session.session_id,
            session_token: session.session_token,
            idle_expires_at: session.idle_expires_at,
            absolute_expires_at: session.absolute_expires_at
        };
        try {
            window.sessionStorage.setItem(storageKey, JSON.stringify(sessionMemory));
        } catch (error) {
            return;
        }
    }

    function clearSession() {
        sessionMemory = null;
        try {
            window.sessionStorage.removeItem(storageKey);
        } catch (error) {
            return;
        }
    }

    function validSession(session) {
        return Boolean(session &&
            /^cs_[A-Za-z0-9_-]{20,100}$/.test(session.session_id || '') &&
            typeof session.session_token === 'string' && session.session_token.length >= 40 &&
            !Number.isNaN(Date.parse(session.absolute_expires_at)));
    }

    function friendlyError(error) {
        if (error && error.code === 'session_limit_reached') {
            return 'This conversation has reached its message limit. Restart to continue.';
        }
        if (error && error.status === 429) {
            return 'Too many requests. Please wait a moment and try again.';
        }
        if (error && (error.code === 'invalid_session' || error.code === 'session_expired')) {
            return 'Your conversation expired. Send your message again to start a new one.';
        }
        return 'We could not send that message. Please try again.';
    }

    function safeHttpUrl(value) {
        if (typeof value !== 'string' || !value) {
            return null;
        }
        try {
            var parsed = new URL(value, document.baseURI);
            return parsed.protocol === 'https:' || parsed.protocol === 'http:' ? parsed.href : null;
        } catch (error) {
            return null;
        }
    }

    function element(tag, className) {
        var node = document.createElement(tag);
        node.className = className;
        return node;
    }

    function iconButton(label, symbol) {
        var button = element('button', 'askasio-icon-button');
        button.type = 'button';
        button.setAttribute('aria-label', label);
        button.textContent = symbol;
        return button;
    }

    function stringValue(value, fallback) {
        return typeof value === 'string' && value.trim() ? value : fallback;
    }

    function positiveInteger(value, fallback) {
        return Number.isInteger(value) && value > 0 ? value : fallback;
    }

    function iconSymbol(value) {
        if (value === 'help') {
            return '?';
        }
        if (value === 'bubble') {
            return '\u25cf';
        }
        return '\u25cc';
    }

    function randomId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return Array.prototype.map.call(bytes, function (byte) {
            return byte.toString(16).padStart(2, '0');
        }).join('');
    }

    function uniqueId(suffix) {
        return 'askasio-' + chatbotId.slice(-8) + '-' + suffix;
    }
}());
