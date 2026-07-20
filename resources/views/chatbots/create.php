<?php $oldName = $old['name'] ?? ''; $oldDescription = $old['description'] ?? ''; ?>
<div class="page-heading page-heading-compact">
    <div>
        <p class="breadcrumbs"><a href="/admin/chatbots">Chatbots</a> <span>/</span> Create chatbot</p>
        <h1>Create chatbot</h1>
        <p class="muted">Start with a private draft. Nothing becomes public until you add knowledge, configure an origin, and publish.</p>
    </div>
</div>

<?php if (is_string($error) && $error !== ''): ?><div class="alert alert-error" role="alert"><?= $escape($error) ?></div><?php endif; ?>

<form method="post" action="/admin/chatbots" class="panel form-panel">
    <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
    <div class="form-group">
        <label for="name">Internal name</label>
        <input id="name" name="name" type="text" value="<?= $escape($oldName) ?>" maxlength="190" required autofocus>
        <p class="field-help">Used only in the administrator dashboard. It also seeds the initial public display name.</p>
    </div>
    <div class="form-group">
        <label for="description">Internal description <span class="muted">(optional)</span></label>
        <textarea id="description" name="description" maxlength="2000" rows="4"><?= $escape($oldDescription) ?></textarea>
    </div>
    <div class="alert alert-warning">The installation provider and models are applied automatically. Provider credentials and model selection are not chatbot settings.</div>
    <div class="form-actions">
        <a class="button button-quiet" href="/admin/chatbots">Cancel</a>
        <button class="button button-primary" type="submit"><svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-plus"></use></svg><span>Create draft</span></button>
    </div>
</form>
