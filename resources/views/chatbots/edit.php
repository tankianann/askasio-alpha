<?php
$draft = $chatbot->draft;
$presentation = $draft->presentation;
$appearance = $draft->appearance;
$field = static fn (string $key, mixed $default): mixed => array_key_exists($key, $old) ? $old[$key] : $default;
$selectedLayout = $field('layout', $appearance['layout'] ?? 'floating') === 'inline_fullscreen' ? 'inline_fullscreen' : 'floating';
$widgetScriptUrl = $applicationUrl . '/chat-widget/v1.js';
$floatingEmbedCode = sprintf(
    '<script src="%s" data-chatbot-id="%s" async></script>',
    $widgetScriptUrl,
    $chatbot->publicId,
);
$inlineEmbedCode = sprintf(
    "<div id=\"ask-asio-search\"></div>\n<script src=\"%s\" data-chatbot-id=\"%s\" data-container-id=\"ask-asio-search\" async></script>",
    $widgetScriptUrl,
    $chatbot->publicId,
);
$questions = implode("\n", $presentation['suggested_questions']);
$originText = implode("\n", $chatbot->assignments->origins);
$assignedIds = $chatbot->assignments->sourceIds;
$archived = $chatbot->status === \App\Domain\Chatbots\ChatbotStatus::Archived;
$assignedReadyCount = count(array_filter(
    $assignedIds,
    static fn (int $sourceId): bool => ($readiness[$sourceId] ?? null)?->isReady() === true,
));
$publicationIssues = [];

if (!$providerConfigured) {
    $publicationIssues[] = ['The installation provider is not configured.', null];
}

if ($assignedIds === []) {
    $publicationIssues[] = ['Assign at least one knowledge source.', 'knowledge'];
} elseif ($assignedReadyCount !== count($assignedIds)) {
    $publicationIssues[] = ['Resolve sources that are not ready for publication.', 'knowledge'];
}

if ($chatbot->assignments->origins === []) {
    $publicationIssues[] = ['Add at least one allowed browser origin.', 'access'];
}
?>
<div class="page-heading page-heading-compact">
    <div><p class="breadcrumbs"><a href="/admin/chatbots">Chatbots</a> <span>/</span> Edit</p><h1><?= $escape($chatbot->name) ?></h1><p class="muted">Draft revision <?= $escape($draft->revision) ?> · <?php if ($chatbot->isPublished()): ?>active publication available<?php else: ?>not yet published<?php endif; ?></p></div>
    <?php if (!$archived): ?><div class="action-row"><a class="button button-quiet" href="/admin/chatbots/<?= $escape($chatbot->id) ?>/preview">Preview draft</a><form method="post" action="/admin/chatbots/<?= $escape($chatbot->id) ?>/publish?tab=<?= $escape($activeTab) ?>"><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><button class="button button-primary" type="submit" <?= $publicationIssues !== [] ? 'disabled' : '' ?>>Publish current draft</button></form></div><?php endif; ?>
</div>

<?php if (is_string($success) && $success !== ''): ?><div class="alert alert-success" role="status"><?= $escape($success) ?></div><?php endif; ?>
<?php if (is_string($error) && $error !== ''): ?><div class="alert alert-error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
<?php if (is_string($formError) && $formError !== ''): ?><div class="alert alert-error" role="alert"><?= $escape($formError) ?></div><?php endif; ?>
<?php if ($archived): ?><div class="alert alert-warning">This chatbot is archived. Its draft, public ID, and publication are read-only; permanent deletion remains available below.</div><?php endif; ?>
<?php if (!$providerConfigured): ?><div class="alert alert-error" role="alert">The installation chat or embedding model is not configured. Draft editing remains available, but publication is blocked.</div><?php endif; ?>

<section class="metadata-panel panel chatbot-metadata" aria-label="Chatbot state">
    <div><span>Status</span><strong><?= $escape(ucfirst($chatbot->status->value)) ?></strong></div>
    <div><span>Public ID</span><code><?= $escape($chatbot->publicId) ?></code></div>
    <div><span>Provider</span><strong><?= $escape($providerConfigured ? $provider->chatProvider : 'Not configured') ?></strong></div>
    <div><span>Chat model</span><code><?= $escape($providerConfigured ? $provider->chatModel : 'Not configured') ?></code></div>
    <div><span>Embedding model</span><code><?= $escape($providerConfigured ? $provider->embeddingModel : 'Not configured') ?><?= $providerConfigured && $provider->embeddingDimensions !== null ? ' · ' . $escape($provider->embeddingDimensions) . ' dimensions' : '' ?></code></div>
    <div><span>Publication</span><strong><?= $chatbot->isPublished() ? 'Active' : 'Draft only' ?></strong></div>
</section>

<nav class="resource-tabs" aria-label="Chatbot configuration">
    <?php foreach (['settings' => 'Settings', 'knowledge' => 'Knowledge', 'access' => 'Access', 'lifecycle' => 'Lifecycle'] as $tab => $label): ?>
        <a href="/admin/chatbots/<?= $escape($chatbot->id) ?>/edit?tab=<?= $escape($tab) ?>" <?= $activeTab === $tab ? 'aria-current="page"' : '' ?>>
            <span><?= $escape($label) ?></span>
            <?php if ($tab === 'knowledge'): ?><small><?= $escape(count($assignedIds)) ?></small><?php elseif ($tab === 'access'): ?><small><?= $escape(count($chatbot->assignments->origins)) ?></small><?php endif; ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php if (!$archived && $publicationIssues !== []): ?>
    <aside class="publication-readiness" aria-label="Publication requirements">
        <strong>Before publishing</strong>
        <ul><?php foreach ($publicationIssues as [$issue, $tab]): ?><li><?php if (is_string($tab)): ?><a href="/admin/chatbots/<?= $escape($chatbot->id) ?>/edit?tab=<?= $escape($tab) ?>"><?= $escape($issue) ?></a><?php else: ?><?= $escape($issue) ?><?php endif; ?></li><?php endforeach; ?></ul>
    </aside>
<?php endif; ?>

<?php if ($activeTab === 'settings'): ?>
<div class="section-heading"><h2>Configuration</h2><p>Only implemented, validated settings are shown. Saving changes does not publish them.</p></div>
<form method="post" action="/admin/chatbots/<?= $escape($chatbot->id) ?>?tab=settings" class="panel form-panel chatbot-form">
    <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="revision" value="<?= $escape($draft->revision) ?>">
    <fieldset <?= $archived ? 'disabled' : '' ?>>
        <h3>Basic information</h3>
        <div class="form-grid"><div class="form-group"><label for="name">Internal name</label><input id="name" name="name" type="text" maxlength="190" required value="<?= $escape($field('name', $chatbot->name)) ?>"></div><div class="form-group"><label for="display-name">Public display name</label><input id="display-name" name="display_name" type="text" maxlength="100" required value="<?= $escape($field('display_name', $presentation['display_name'])) ?>"></div></div>
        <div class="form-group"><label for="description">Internal description</label><textarea id="description" name="description" maxlength="2000" rows="3"><?= $escape($field('description', $chatbot->description ?? '')) ?></textarea></div>

        <h3>Grounded behavior</h3>
        <div class="form-group"><label for="instructions">System instructions</label><textarea id="instructions" name="system_instructions" maxlength="12000" rows="7" required><?= $escape($field('system_instructions', $draft->systemInstructions)) ?></textarea><p class="field-help">Server-only instructions. They are never returned in public configuration.</p></div>
        <div class="form-group"><label for="fallback">Fallback message</label><textarea id="fallback" name="fallback_message" maxlength="1000" rows="3" required><?= $escape($field('fallback_message', $draft->fallbackMessage)) ?></textarea></div>
        <div class="form-grid form-grid-three"><div class="form-group"><label for="top-k">Retrieved chunks</label><input id="top-k" name="retrieval_top_k" type="number" min="1" max="<?= $escape($maximumTopK) ?>" required value="<?= $escape($field('retrieval_top_k', $draft->retrievalTopK)) ?>"></div><div class="form-group"><label for="similarity">Minimum similarity</label><input id="similarity" name="minimum_similarity" type="number" min="0" max="1" step="0.00001" required value="<?= $escape($field('minimum_similarity', $draft->minimumSimilarity)) ?>"></div><div class="form-group checkbox-group"><label><input name="citations_enabled" type="checkbox" value="1" <?= $field('citations_enabled', $draft->citationsEnabled ? '1' : '') === '1' ? 'checked' : '' ?>> Show citations publicly</label></div></div>

        <h3>Conversation</h3>
        <div class="form-group"><label for="welcome">Welcome message</label><textarea id="welcome" name="welcome_message" maxlength="1000" rows="3" required><?= $escape($field('welcome_message', $presentation['welcome_message'])) ?></textarea></div>
        <div class="form-grid"><div class="form-group"><label for="placeholder">Input placeholder</label><input id="placeholder" name="input_placeholder" type="text" maxlength="160" required value="<?= $escape($field('input_placeholder', $presentation['input_placeholder'])) ?>"></div><div class="form-group"><label for="retention">Transcript retention</label><select id="retention" name="retention_days"><?php foreach ([0 => 'Active session only', 7 => '7 days', 30 => '30 days', 90 => '90 days'] as $days => $label): ?><option value="<?= $escape($days) ?>" <?= (string) $field('retention_days', $draft->retentionDays) === (string) $days ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></div></div>
        <div class="form-group"><label for="questions">Suggested questions</label><textarea id="questions" name="suggested_questions" rows="5" placeholder="One question per line"><?= $escape($field('suggested_questions', $questions)) ?></textarea><p class="field-help">At most 6 questions, 200 characters each.</p></div>
        <div class="form-grid form-grid-three"><div class="form-group"><label for="message-chars">Maximum message characters</label><input id="message-chars" name="maximum_message_characters" type="number" min="1" max="<?= $escape($maximumMessageCharacters) ?>" required value="<?= $escape($field('maximum_message_characters', $draft->maximumMessageCharacters)) ?>"></div><div class="form-group"><label for="message-count">Maximum messages</label><input id="message-count" name="maximum_messages_per_session" type="number" min="1" max="100" required value="<?= $escape($field('maximum_messages_per_session', $draft->maximumMessagesPerSession)) ?>"></div><div class="form-group"><label for="idle">Idle expiry (minutes)</label><input id="idle" name="idle_expiry_minutes" type="number" min="5" max="1440" required value="<?= $escape($field('idle_expiry_minutes', $draft->idleExpiryMinutes)) ?>"></div></div>
        <div class="form-grid"><div class="form-group"><label for="absolute">Absolute expiry (minutes)</label><input id="absolute" name="absolute_expiry_minutes" type="number" min="5" max="10080" required value="<?= $escape($field('absolute_expiry_minutes', $draft->absoluteExpiryMinutes)) ?>"></div><div class="form-group"><label for="privacy">Privacy notice URL</label><input id="privacy" name="privacy_notice_url" type="url" maxlength="2048" value="<?= $escape($field('privacy_notice_url', $draft->privacyNoticeUrl ?? '')) ?>" placeholder="https://example.com/privacy"></div></div>
        <div class="form-group"><label for="disclosure">Automated-assistant disclosure</label><textarea id="disclosure" name="disclosure_text" maxlength="500" rows="3" required><?= $escape($field('disclosure_text', $draft->disclosureText)) ?></textarea></div>

        <h3>Appearance</h3>
        <div class="form-group"><label for="layout">Widget layout</label><select id="layout" name="layout"><?php foreach (['floating' => 'Floating launcher', 'inline_fullscreen' => 'Inline search, then fullscreen chat'] as $value => $label): ?><option value="<?= $value ?>" <?= $field('layout', $appearance['layout'] ?? 'floating') === $value ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select><p class="field-help">The floating layout uses the position and panel-size settings below. The inline layout renders in its embed container and expands after the visitor submits a question.</p></div>
        <div class="form-grid form-grid-three"><div class="form-group"><label for="accent">Accent color</label><input id="accent" name="accent" type="text" pattern="#[0-9A-Fa-f]{6}" maxlength="7" required value="<?= $escape($field('accent', $appearance['accent'])) ?>"></div><div class="form-group"><label for="theme">Theme</label><select id="theme" name="theme"><?php foreach (['light', 'dark'] as $value): ?><option value="<?= $value ?>" <?= $field('theme', $appearance['theme']) === $value ? 'selected' : '' ?>><?= ucfirst($value) ?></option><?php endforeach; ?></select></div><div class="form-group"><label for="position">Position</label><select id="position" name="position"><?php foreach (['left', 'right'] as $value): ?><option value="<?= $value ?>" <?= $field('position', $appearance['position']) === $value ? 'selected' : '' ?>><?= ucfirst($value) ?></option><?php endforeach; ?></select></div></div>
        <div class="form-grid"><div class="form-group"><label for="launcher-label">Launcher label</label><input id="launcher-label" name="launcher_label" type="text" maxlength="50" required value="<?= $escape($field('launcher_label', $appearance['launcher_label'])) ?>"></div><div class="form-group"><label for="launcher-icon">Launcher icon</label><select id="launcher-icon" name="launcher_icon"><?php foreach (['chat', 'bubble', 'help'] as $value): ?><option value="<?= $value ?>" <?= $field('launcher_icon', $appearance['launcher_icon']) === $value ? 'selected' : '' ?>><?= ucfirst($value) ?></option><?php endforeach; ?></select></div></div>
        <div class="form-grid"><div class="form-group"><label for="panel-title">Panel title</label><input id="panel-title" name="panel_title" type="text" maxlength="100" required value="<?= $escape($field('panel_title', $appearance['panel_title'])) ?>"></div><div class="form-group"><label for="size">Panel size</label><select id="size" name="size"><?php foreach (['compact', 'standard'] as $value): ?><option value="<?= $value ?>" <?= $field('size', $appearance['size']) === $value ? 'selected' : '' ?>><?= ucfirst($value) ?></option><?php endforeach; ?></select></div></div>
        <div class="form-actions"><button class="button button-primary" type="submit">Save draft settings</button></div>
    </fieldset>
</form>

<div class="section-heading"><h2>Installation code</h2><p>Save and publish this chatbot, then paste the code into the page where it should appear.</p></div>
<section class="panel form-panel widget-installation" data-widget-installation data-layout-select="layout">
    <div class="form-group" data-widget-snippet="floating" <?= $selectedLayout === 'floating' ? '' : 'hidden' ?>>
        <label for="floating-embed-code">Floating launcher</label>
        <textarea id="floating-embed-code" class="embed-code" rows="3" readonly spellcheck="false"><?= $escape($floatingEmbedCode) ?></textarea>
        <p class="field-help">Paste this before the closing <code>&lt;/body&gt;</code> tag. The launcher floats over the page.</p>
    </div>
    <div class="form-group" data-widget-snippet="inline_fullscreen" <?= $selectedLayout === 'inline_fullscreen' ? '' : 'hidden' ?>>
        <label for="inline-embed-code">Inline search and fullscreen chat</label>
        <textarea id="inline-embed-code" class="embed-code" rows="5" readonly spellcheck="false"><?= $escape($inlineEmbedCode) ?></textarea>
        <p class="field-help">Place this where the full-width search box should appear. You may rename the container ID, provided both occurrences match.</p>
    </div>
    <div class="form-actions">
        <button class="button button-primary" type="button" data-widget-copy-button data-copy-target="<?= $selectedLayout === 'inline_fullscreen' ? 'inline-embed-code' : 'floating-embed-code' ?>">Copy embed code</button>
    </div>
</section>
<?php endif; ?>

<?php if ($activeTab === 'knowledge'): ?>
    <div class="section-heading"><h2>Knowledge sources</h2><p>Add or remove several sources in one draft update. Publication uses only ready, embedding-compatible sources.</p></div>
    <section class="source-assignment-summary" aria-label="Source assignment summary">
        <article><span>Assigned</span><strong><?= $escape(count($assignedIds)) ?></strong></article>
        <article><span>Ready</span><strong><?= $escape($assignedReadyCount) ?></strong></article>
        <article><span>Need attention</span><strong><?= $escape(count($assignedIds) - $assignedReadyCount) ?></strong></article>
    </section>

    <div class="subsection-heading"><div><h3>Assigned sources</h3><p>Select several sources to remove them together.</p></div></div>
    <?php if ($assignedSources === []): ?>
        <section class="empty-state compact-empty"><h3>No sources assigned</h3><p>Select sources from the catalog below before publishing.</p></section>
    <?php else: ?>
        <form method="post" action="<?= $escape($sourceAssignmentUrl) ?>" class="source-batch-form" data-source-batch-form>
            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="revision" value="<?= $escape($draft->revision) ?>">
            <input type="hidden" name="assignment_action" value="remove">
            <div class="table-card"><table><thead><tr><th class="selection-cell"><input type="checkbox" aria-label="Select all assigned sources" data-select-all <?= $archived ? 'disabled' : '' ?>></th><th>Assigned source</th><th>Type</th><th>Publication readiness</th></tr></thead><tbody>
                <?php foreach ($assignedSources as $source): ?><?php $ready = $readiness[$source->id] ?? null; ?>
                    <tr><td class="selection-cell"><input type="checkbox" name="source_ids[]" value="<?= $escape($source->id) ?>" aria-label="Select <?= $escape($source->name) ?>" data-source-selection <?= $archived ? 'disabled' : '' ?>></td><td><a class="table-link" href="/admin/sources/<?= $escape($source->id) ?>"><?= $escape($source->name) ?></a></td><td><?= $escape($source->type->label()) ?></td><td><span class="badge badge-<?= $ready?->isReady() ? 'ready' : 'failed' ?>"><?= $escape($ready === null ? 'Unknown' : ucwords(str_replace('_', ' ', $ready->status->value))) ?></span></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
            <?php if (!$archived): ?><div class="batch-action-bar"><span data-selection-summary>Select sources to remove</span><button class="button button-quiet" type="submit">Remove selected</button></div><?php endif; ?>
        </form>
    <?php endif; ?>

    <?php if (!$archived && $sourceQuery !== null && $sourcePage !== null): ?>
        <div class="subsection-heading"><div><h3>Source catalog</h3><p>Search the Knowledge Base, then add all selected sources with one action.</p></div></div>
        <form class="panel filter-panel compact-filter-panel source-picker" method="get" action="/admin/chatbots/<?= $escape($chatbot->id) ?>/edit">
            <input type="hidden" name="tab" value="knowledge">
            <div class="filter-heading"><div><h3>Find sources</h3><p>The catalog is filtered and paginated in the database.</p></div><?php if ($sourceQuery->hasActiveFilters()): ?><a class="text-link" href="/admin/chatbots/<?= $escape($chatbot->id) ?>/edit?tab=knowledge">Clear filters</a><?php endif; ?></div>
            <div class="filter-grid"><label class="filter-field filter-field-wide"><span>Search source name</span><input type="search" name="search" value="<?= $escape($sourceQuery->search) ?>" maxlength="190"></label><label class="filter-field"><span>Source type</span><select name="type"><option value="">All types</option><?php foreach (\App\Domain\Sources\SourceType::cases() as $type): ?><option value="<?= $escape($type->value) ?>" <?= $sourceQuery->type === $type ? 'selected' : '' ?>><?= $escape($type->label()) ?></option><?php endforeach; ?></select></label><label class="filter-field"><span>Availability</span><select name="availability"><option value="all">All</option><option value="enabled" <?= $sourceQuery->availability->value === 'enabled' ? 'selected' : '' ?>>Enabled</option><option value="disabled" <?= $sourceQuery->availability->value === 'disabled' ? 'selected' : '' ?>>Disabled</option><option value="deleted" <?= $sourceQuery->availability->value === 'deleted' ? 'selected' : '' ?>>Deleted</option></select></label></div>
            <div class="filter-actions"><button class="button button-primary" type="submit">Find sources</button></div>
        </form>
        <?php if ($sourcePage->items === []): ?>
            <section class="empty-state compact-empty"><h3>No sources match</h3><p>Add knowledge in the Knowledge Base or adjust these filters.</p></section>
        <?php else: ?>
            <form method="post" action="<?= $escape($sourceAssignmentUrl) ?>" class="source-batch-form" data-source-batch-form>
                <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="revision" value="<?= $escape($draft->revision) ?>">
                <input type="hidden" name="assignment_action" value="add">
                <div class="table-card"><table><thead><tr><th class="selection-cell"><input type="checkbox" aria-label="Select all available sources on this page" data-select-all></th><th>Source</th><th>Availability</th><th>Active-version readiness</th><th>Assignment</th></tr></thead><tbody>
                    <?php foreach ($sourcePage->items as $source): ?><?php $ready = $readiness[$source->id] ?? null; $assigned = in_array($source->id, $assignedIds, true); ?>
                        <tr class="<?= $source->isDeleted() ? 'row-muted' : '' ?>"><td class="selection-cell"><?php if (!$assigned && !$source->isDeleted()): ?><input type="checkbox" name="source_ids[]" value="<?= $escape($source->id) ?>" aria-label="Select <?= $escape($source->name) ?>" data-source-selection><?php else: ?>—<?php endif; ?></td><td><?= $escape($source->name) ?><span class="table-subtitle"><?= $escape($source->type->label()) ?></span></td><td><span class="badge badge-<?= $escape($source->isDeleted() ? 'deleted' : $source->status->value) ?>"><?= $escape($source->isDeleted() ? 'Deleted' : ucfirst($source->status->value)) ?></span></td><td><span class="badge badge-<?= $ready?->isReady() ? 'ready' : 'failed' ?>"><?= $escape($ready === null ? 'Unknown' : ucwords(str_replace('_', ' ', $ready->status->value))) ?></span></td><td><?= $assigned ? '<span class="badge badge-ready">Assigned</span>' : ($source->isDeleted() ? 'Unavailable' : 'Available') ?></td></tr>
                    <?php endforeach; ?>
                </tbody></table></div>
                <div class="batch-action-bar"><span data-selection-summary>Select sources to add</span><button class="button button-primary" type="submit">Add selected</button></div>
                <?php $page = $sourcePage; require dirname(__DIR__) . '/partials/list_pagination.php'; unset($page); ?>
            </form>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<?php if ($activeTab === 'access'): ?>
    <div class="section-heading"><h2>Allowed browser origins</h2><p>Control which websites may load this chatbot. Use one exact HTTPS origin per line; localhost may use HTTP for development.</p></div>
    <form method="post" action="/admin/chatbots/<?= $escape($chatbot->id) ?>/origins?tab=access" class="panel form-panel"><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="revision" value="<?= $escape($draft->revision) ?>"><fieldset <?= $archived ? 'disabled' : '' ?>><div class="form-group"><label for="origins">Origins</label><textarea id="origins" name="origins" rows="8" placeholder="https://www.example.com"><?= $escape($field('origins', $originText)) ?></textarea><p class="field-help">Paths, wildcards, credentials, query strings, and fragments are rejected.</p></div><div class="form-actions"><button class="button button-primary" type="submit">Save allowed origins</button></div></fieldset></form>
<?php endif; ?>

<?php if ($activeTab === 'lifecycle'): ?>
    <div class="section-heading"><h2>Lifecycle</h2><p>Availability changes are immediate; draft changes remain private until publication.</p></div>
    <section class="panel form-panel lifecycle-panel"><div class="action-row"><?php if (!$archived): ?><?php if ($chatbot->status === \App\Domain\Chatbots\ChatbotStatus::Active): ?><form method="post" action="/admin/chatbots/<?= $escape($chatbot->id) ?>/disable?tab=lifecycle"><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><button class="button button-quiet" type="submit">Disable</button></form><?php else: ?><form method="post" action="/admin/chatbots/<?= $escape($chatbot->id) ?>/enable?tab=lifecycle"><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><button class="button button-quiet" type="submit">Enable</button></form><?php endif; ?><form method="post" action="/admin/chatbots/<?= $escape($chatbot->id) ?>/rotate-public-id?tab=lifecycle" data-confirm="Rotate the public ID? Previous embed references will stop working."><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><button class="button button-quiet" type="submit">Rotate public ID</button></form><form method="post" action="/admin/chatbots/<?= $escape($chatbot->id) ?>/archive?tab=lifecycle" data-confirm="Archive this chatbot? It cannot be restored through the current lifecycle."><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><button class="button button-danger" type="submit">Archive</button></form><?php else: ?><span class="muted">Archived chatbots cannot be edited, enabled, published, or rotated.</span><?php endif; ?></div></section>
    <?php if ($archived): ?><section class="panel form-panel danger-zone"><h2>Permanently delete chatbot</h2><p class="muted">This removes the draft and all immutable publication history. Type <strong><?= $escape($chatbot->name) ?></strong> exactly. This cannot be undone.</p><form method="post" action="/admin/chatbots/<?= $escape($chatbot->id) ?>/permanent-delete?tab=lifecycle"><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><div class="form-group"><label for="confirmation">Confirmation</label><input id="confirmation" name="confirmation" type="text" autocomplete="off" required></div><button class="button button-danger" type="submit">Permanently delete</button></form></section><?php endif; ?>
<?php endif; ?>
