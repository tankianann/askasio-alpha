<?php
$messages = $state?->messages ?? [];
$previewSession = $state?->session;
$latestDiagnostics = null;

foreach (array_reverse($messages) as $candidate) {
    if ($candidate->role === \App\Domain\Chatbots\ChatbotMessageRole::Assistant && $candidate->retrieval !== null) {
        $latestDiagnostics = $candidate;
        break;
    }
}
?>
<div class="page-heading page-heading-compact">
    <div><p class="breadcrumbs"><a href="/admin/chatbots">Chatbots</a> <span>/</span> <a href="/admin/chatbots/<?= $escape($chatbot->id) ?>/edit"><?= $escape($chatbot->name) ?></a> <span>/</span> Preview</p><h1>Draft preview</h1><p class="muted">Administrator-only test traffic. Responses use an immutable snapshot of the draft.</p></div>
    <form method="post" action="/admin/chatbots/<?= $escape($chatbot->id) ?>/preview/restart"><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><button class="button button-quiet" type="submit" <?= !$providerConfigured ? 'disabled' : '' ?>>Restart test session</button></form>
</div>

<?php if (is_string($success) && $success !== ''): ?><div class="alert alert-success" role="status"><?= $escape($success) ?></div><?php endif; ?>
<?php if (is_string($error) && $error !== ''): ?><div class="alert alert-error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
<?php if (!$providerConfigured): ?><div class="alert alert-error" role="alert">Configure the installation chat and embedding models before running preview.</div><?php endif; ?>
<?php if ($previewSession !== null && $previewSession->previewDraftRevision !== $chatbot->draft->revision): ?><div class="alert alert-warning">This conversation uses draft revision <?= $escape($previewSession->previewDraftRevision) ?>. Sending another message automatically starts a fresh revision <?= $escape($chatbot->draft->revision) ?> test session.</div><?php endif; ?>

<section class="metadata-panel panel" aria-label="Preview state">
    <div><span>Traffic</span><strong>Test only</strong></div>
    <div><span>Draft snapshot</span><strong><?= $escape($previewSession?->previewDraftRevision ?? $chatbot->draft->revision) ?></strong></div>
    <div><span>Status</span><strong><?= $escape($previewSession === null ? 'Not started' : ucfirst($previewSession->status->value)) ?></strong></div>
    <div><span>User turns</span><strong><?= $escape($previewSession?->messageCount ?? 0) ?></strong></div>
</section>

<div class="preview-layout">
    <section class="panel preview-conversation" aria-label="Test conversation">
        <div class="section-heading"><h2>Conversation</h2><p>Content is stored and retained using the draft snapshot policy.</p></div>
        <?php if ($messages === []): ?><div class="empty-state compact-empty"><h3>No test messages yet</h3><p>Ask a question to start a draft-scoped test session.</p></div><?php else: ?><div class="preview-messages"><?php foreach ($messages as $message): ?><article class="preview-message preview-message-<?= $escape($message->role->value) ?>"><div class="preview-message-heading"><strong><?= $escape($message->role === \App\Domain\Chatbots\ChatbotMessageRole::User ? 'Administrator' : 'Assistant') ?></strong><span><?= $escape(ucfirst($message->status->value)) ?></span></div><?php if ($message->content !== null): ?><p><?= nl2br($escape($message->content)) ?></p><?php elseif ($message->errorCode !== null): ?><p class="muted">Safe failure: <?= $escape(str_replace('_', ' ', $message->errorCode)) ?></p><?php endif; ?><?php if ($message->citations !== null && $message->citations !== []): ?><ul class="preview-citations"><?php foreach ($message->citations as $citation): ?><li><strong><?= $escape($citation['reference'] ?? '') ?></strong> <?= $escape($citation['title'] ?? '') ?><?php if (is_string($citation['heading'] ?? null)): ?> · <?= $escape($citation['heading']) ?><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?></article><?php endforeach; ?></div><?php endif; ?>
        <form class="preview-composer" method="post" action="/admin/chatbots/<?= $escape($chatbot->id) ?>/preview/messages"><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="idempotency_key" value="<?= $escape($idempotencyKey) ?>"><input type="hidden" name="request_id" value="<?= $escape($requestId) ?>"><label for="preview-message">Test message</label><textarea id="preview-message" name="message" rows="4" maxlength="<?= $escape($chatbot->draft->maximumMessageCharacters) ?>" required></textarea><div class="form-actions"><button class="button button-primary" type="submit" <?= !$providerConfigured ? 'disabled' : '' ?>>Send test message</button></div></form>
    </section>

    <aside class="panel preview-diagnostics" aria-label="Latest response diagnostics"><div class="section-heading"><h2>Latest diagnostics</h2><p>Administrator-only and bounded; never part of the public citation payload.</p></div><?php if ($latestDiagnostics === null): ?><p class="muted">Diagnostics appear after a completed response.</p><?php else: ?><?php $diagnostics = $latestDiagnostics->retrieval ?? []; ?><dl><dt>Provider / model</dt><dd><?= $escape(($latestDiagnostics->provider ?? '—') . ' / ' . ($latestDiagnostics->model ?? '—')) ?></dd><dt>Latency</dt><dd><?= $escape($latestDiagnostics->latencyMs ?? 0) ?> ms</dd><dt>Usage</dt><dd><?= $escape($latestDiagnostics->inputTokens) ?> input · <?= $escape($latestDiagnostics->outputTokens) ?> output · <?= $escape($latestDiagnostics->embeddingTokens) ?> embedding · <?= $escape($latestDiagnostics->providerTokens) ?> total</dd><dt>Outcome</dt><dd><?= ($diagnostics['fallback'] ?? false) === true ? 'Grounded fallback' : 'Grounded answer' ?></dd><dt>Context</dt><dd><?= $escape($diagnostics['retrieved_chunks'] ?? 0) ?> chunks · <?= $escape($diagnostics['context_tokens'] ?? 0) ?> estimated tokens</dd><dt>History</dt><dd><?= $escape($diagnostics['history_messages'] ?? 0) ?> messages · <?= $escape($diagnostics['history_tokens'] ?? 0) ?> estimated tokens</dd></dl><?php $matches = is_array($diagnostics['matches'] ?? null) ? $diagnostics['matches'] : []; ?><?php if ($matches !== []): ?><h3>Admitted matches</h3><ol class="diagnostic-matches"><?php foreach ($matches as $match): ?><li><strong><?= $escape($match['reference'] ?? '') ?></strong> · source <?= $escape($match['source_id'] ?? '') ?> · similarity <?= $escape($match['similarity'] ?? '') ?><p><?= $escape($match['excerpt'] ?? '') ?></p></li><?php endforeach; ?></ol><?php endif; ?><?php endif; ?></aside>
</div>
