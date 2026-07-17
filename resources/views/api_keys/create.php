<?php
$oldName = $old['name'] ?? '';
$oldExpiry = $old['expires_at'] ?? '';
?>
<div class="page-heading page-heading-compact">
    <div>
        <p class="breadcrumbs"><a href="/admin/api-keys">API keys</a> <span>/</span> Create</p>
        <h1>Create API key</h1>
        <p class="muted">The complete bearer key will be shown only in the next response.</p>
    </div>
</div>

<?php if (is_string($error) && $error !== ''): ?>
    <div class="alert alert-error" role="alert"><?= $escape($error) ?></div>
<?php endif; ?>

<form method="post" action="/admin/api-keys" class="panel form-panel">
    <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">

    <div class="form-group">
        <label for="name">Key name</label>
        <input id="name" name="name" type="text" value="<?= $escape($oldName) ?>" maxlength="190" required autofocus>
        <p class="field-help">Use the client or integration name, such as “Support website”.</p>
    </div>

    <div class="form-group">
        <label for="expires-at">Expiry date and time <span class="muted">(optional)</span></label>
        <input id="expires-at" name="expires_at" type="datetime-local" value="<?= $escape($oldExpiry) ?>">
        <p class="field-help">Interpreted in the application timezone. Leave empty for no automatic expiry.</p>
    </div>

    <div class="form-actions">
        <a class="button button-quiet" href="/admin/api-keys">Cancel</a>
        <button class="button button-primary" type="submit">Create API key</button>
    </div>
</form>
