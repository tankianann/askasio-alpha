<div class="page-heading page-heading-compact">
    <div>
        <p class="breadcrumbs"><a href="/admin/sources">Knowledge Base</a> <span>/</span> <a href="/admin/sources/<?= $escape($source->id) ?>"><?= $escape($source->name) ?></a> <span>/</span> Revision <?= $escape($version->versionNumber) ?></p>
        <div class="title-row">
            <h1>Revision <?= $escape($version->versionNumber) ?></h1>
            <span class="badge badge-<?= $escape($version->processingStatus->value) ?>"><?= $escape(ucfirst($version->processingStatus->value)) ?></span>
        </div>
        <p class="muted">Detailed metadata and extracted content for this immutable revision.</p>
    </div>
    <a class="button button-quiet" href="<?= $escape($backUrl) ?>">Back to document</a>
</div>

<section class="panel metadata-panel">
    <div><span>Revision ID</span><strong><?= $escape($version->id) ?></strong></div>
    <div><span>Created</span><strong><?= $escape($formatDate($version->createdAt)) ?></strong></div>
    <div><span>Processed</span><strong><?= $escape($formatDate($version->processedAt)) ?></strong></div>
    <div><span>Activated</span><strong><?= $escape($formatDate($version->activatedAt)) ?></strong></div>
    <div><span>Content segments</span><strong><?= $escape($version->chunkCount) ?></strong></div>
    <div><span>MIME type</span><strong><?= $escape($version->mimeType ?? '—') ?></strong></div>
</section>

<section class="panel version-card">
    <h2>Revision metadata</h2>
    <dl class="definition-grid">
        <?php if ($version->originalUrl !== null): ?>
            <div class="definition-wide"><dt>Original URL</dt><dd><a class="text-link break-text" href="<?= $escape($version->originalUrl) ?>" rel="noreferrer" target="_blank"><?= $escape($version->originalUrl) ?></a></dd></div>
        <?php endif; ?>
        <?php if ($version->originalFilename !== null): ?>
            <div><dt>Original filename</dt><dd><?= $escape($version->originalFilename) ?></dd></div>
            <div><dt>File size</dt><dd><?= $escape($formatBytes($version->fileSize)) ?></dd></div>
            <div class="definition-wide"><dt>Stored path</dt><dd><code class="break-text"><?= $escape($version->storedFilePath) ?></code></dd></div>
            <div class="definition-wide"><dt>File SHA-256</dt><dd><code class="break-text"><?= $escape($version->fileHash) ?></code></dd></div>
        <?php endif; ?>
        <?php if ($version->contentHash !== null): ?>
            <div class="definition-wide"><dt>Extracted content SHA-256</dt><dd><code class="break-text"><?= $escape($version->contentHash) ?></code></dd></div>
        <?php endif; ?>
    </dl>
    <?php if ($version->errorMessage !== null): ?><div class="alert alert-error"><?= $escape($version->errorMessage) ?></div><?php endif; ?>
</section>

<section class="section-heading"><div><h2>Extracted content</h2><p class="muted">Content is loaded only on this revision-specific page and is escaped before display.</p></div></section>
<?php if ($version->extractedText === null || $version->extractedText === ''): ?>
    <div class="panel compact-panel muted">No extracted text is stored for this revision.</div>
<?php else: ?>
    <div class="panel extraction-details"><pre><?= $escape($version->extractedText) ?></pre></div>
<?php endif; ?>

<section class="section-heading"><div><h2>Extracted metadata</h2></div></section>
<?php if ($version->metadata === []): ?>
    <div class="panel compact-panel muted">No extracted metadata is stored for this revision.</div>
<?php else: ?>
    <div class="panel extraction-details"><pre><?= $escape($formatJson($version->metadata)) ?></pre></div>
<?php endif; ?>
