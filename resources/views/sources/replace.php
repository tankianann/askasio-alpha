<div class="page-heading page-heading-compact">
    <div>
        <p class="breadcrumbs"><a href="/admin/sources">Sources</a> <span>/</span> <a href="/admin/sources/<?= $escape($source->id) ?>"><?= $escape($source->name) ?></a> <span>/</span> Replace</p>
        <h1>Upload replacement</h1>
        <p class="muted">The current active version remains searchable until this replacement finishes successfully.</p>
    </div>
</div>

<?php if (is_string($error) && $error !== ''): ?>
    <div class="alert alert-error" role="alert"><?= $escape($error) ?></div>
<?php endif; ?>

<form class="panel form-panel" method="post" action="/admin/sources/<?= $escape($source->id) ?>/replacement" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= $escape($maximumUploadMegabytes * 1024 * 1024) ?>">

    <div class="form-group">
        <label for="replacement_file"><?= $source->type->value === 'pdf' ? 'PDF file' : 'Markdown file' ?></label>
        <input id="replacement_file" name="replacement_file" type="file" required accept="<?= $source->type->value === 'pdf' ? 'application/pdf,.pdf' : 'text/markdown,text/plain,.md,.markdown' ?>">
        <p class="field-help">Maximum upload size: <?= $escape($maximumUploadMegabytes) ?> MB.</p>
    </div>

    <div class="form-actions">
        <button class="button button-primary" type="submit">Queue replacement</button>
        <a class="button button-quiet" href="/admin/sources/<?= $escape($source->id) ?>">Cancel</a>
    </div>
</form>
