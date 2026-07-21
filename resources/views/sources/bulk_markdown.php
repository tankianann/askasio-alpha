<div class="page-heading page-heading-compact">
    <div>
        <p class="breadcrumbs"><a href="/admin/sources">Knowledge Base</a> <span>/</span> Bulk upload Markdown</p>
        <h1>Bulk upload Markdown</h1>
        <p class="muted">Choose multiple Markdown files. Each valid file becomes a separate document and enters the processing queue.</p>
    </div>
</div>

<noscript><div class="alert alert-error" role="alert">JavaScript is required to upload multiple files through the import queue.</div></noscript>

<form
    class="panel form-panel bulk-markdown-form"
    action="/admin/sources/bulk-markdown"
    method="post"
    enctype="multipart/form-data"
    data-bulk-markdown-upload
>
    <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">

    <div class="form-group">
        <label for="bulk-markdown-files">Markdown files</label>
        <label class="bulk-upload-dropzone" for="bulk-markdown-files" data-bulk-markdown-dropzone>
            <strong>Drop Markdown files here</strong>
            <span>or choose files from your computer</span>
            <input
                id="bulk-markdown-files"
                type="file"
                accept=".md,.markdown,text/markdown,text/plain"
                multiple
                data-bulk-markdown-files
            >
        </label>
        <p class="bulk-upload-selection" data-bulk-markdown-selection>No files selected.</p>
        <p class="field-help">Every file must be UTF-8 Markdown, no larger than <?= $escape($maximumUploadMegabytes) ?> MB, with a YAML frontmatter <code>title</code> field.</p>
    </div>

    <div class="frontmatter-example" aria-label="Required frontmatter example">
        <span>Required format</span>
        <pre>---
title: "Document name"
---</pre>
    </div>

    <div class="form-actions">
        <a class="button button-quiet" href="/admin/sources">Back to Knowledge Base</a>
        <button class="button button-primary" type="submit" data-bulk-markdown-submit>Upload selected files</button>
    </div>
</form>

<section class="bulk-upload-results" data-bulk-markdown-results hidden aria-live="polite">
    <div class="result-toolbar">
        <p data-bulk-markdown-summary></p>
        <span data-bulk-markdown-progress></span>
    </div>
    <div class="table-card">
        <table>
            <thead><tr><th>File</th><th>Document</th><th>Status</th></tr></thead>
            <tbody data-bulk-markdown-rows></tbody>
        </table>
    </div>
</section>
