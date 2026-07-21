<?php
$selectedType = $old['source_type'] ?? 'url';
$oldName = $old['name'] ?? '';
$oldUrl = $old['url'] ?? '';
?>
<div class="page-heading page-heading-compact">
    <div>
        <p class="breadcrumbs"><a href="/admin/sources">Knowledge Base</a> <span>/</span> Add knowledge</p>
        <h1>Add knowledge</h1>
        <p class="muted">Choose the content Ask Asio should learn from. Processing begins after it is added.</p>
    </div>
</div>

<?php if (is_string($error) && $error !== ''): ?>
    <div class="alert alert-error" role="alert"><?= $escape($error) ?></div>
<?php endif; ?>

<p class="form-alternative">Adding several Markdown files? <a class="text-link" href="/admin/sources/bulk-markdown">Use the bulk uploader</a>.</p>

<form method="post" action="/admin/sources" enctype="multipart/form-data" class="panel form-panel">
    <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">

    <div class="form-group">
        <label for="name">Document name</label>
        <input id="name" name="name" type="text" value="<?= $escape($oldName) ?>" maxlength="190" required autofocus>
        <p class="field-help">Use a clear, memorable name, such as “Refund policy”.</p>
    </div>

    <fieldset class="form-group">
        <legend>Content type</legend>
        <div class="choice-grid">
            <?php foreach (['url' => 'URL', 'markdown' => 'Markdown', 'pdf' => 'PDF'] as $value => $label): ?>
                <label class="choice-card">
                    <input type="radio" name="source_type" value="<?= $escape($value) ?>" <?= $selectedType === $value ? 'checked' : '' ?>>
                    <span><?= $escape($label) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </fieldset>

    <div class="form-group source-fields" data-source-fields="url">
        <label for="url">Page URL</label>
        <input id="url" name="url" type="url" value="<?= $escape($oldUrl) ?>" maxlength="2048" placeholder="https://example.com/policy">
        <p class="field-help">Only public HTTP and HTTPS pages are accepted. Ask Asio will securely retrieve the page content.</p>
    </div>

    <div class="form-group source-fields" data-source-fields="markdown">
        <label for="markdown-file">Markdown file</label>
        <input id="markdown-file" name="markdown_file" type="file" accept=".md,.markdown,text/markdown,text/plain">
        <p class="field-help">UTF-8 `.md` or `.markdown`, up to <?= $escape($maximumUploadMegabytes) ?> MB.</p>
    </div>

    <div class="form-group source-fields" data-source-fields="pdf">
        <label for="pdf-file">PDF file</label>
        <input id="pdf-file" name="pdf_file" type="file" accept=".pdf,application/pdf">
        <p class="field-help">A valid PDF with a `%PDF-` signature, up to <?= $escape($maximumUploadMegabytes) ?> MB.</p>
    </div>

    <div class="form-actions">
        <a class="button button-quiet" href="/admin/sources">Cancel</a>
        <button class="button button-primary" type="submit">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-plus"></use></svg>
            <span>Add to Knowledge Base</span>
        </button>
    </div>
</form>
