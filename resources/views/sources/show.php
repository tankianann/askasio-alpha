<div class="page-heading page-heading-compact">
    <div>
        <p class="breadcrumbs"><a href="/admin/sources">Sources</a> <span>/</span> <?= $escape($source->name) ?></p>
        <div class="title-row">
            <h1><?= $escape($source->name) ?></h1>
            <?php if ($source->isDeleted()): ?><span class="badge badge-deleted">Deleted</span><?php endif; ?>
        </div>
        <p class="muted"><?= $escape($source->type->label()) ?> source · Created <?= $escape($formatDate($source->createdAt)) ?></p>
    </div>
    <?php if (!$source->isDeleted()): ?>
        <div class="action-row">
            <?php if ($source->status->value === 'enabled'): ?>
                <form method="post" action="/admin/sources/<?= $escape($source->id) ?>/disable">
                    <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                    <button class="button button-quiet" type="submit">Disable</button>
                </form>
            <?php else: ?>
                <form method="post" action="/admin/sources/<?= $escape($source->id) ?>/enable">
                    <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                    <button class="button button-primary" type="submit">Enable</button>
                </form>
            <?php endif; ?>
            <form method="post" action="/admin/sources/<?= $escape($source->id) ?>/delete" data-confirm="Soft-delete this source? Its versions and files will be preserved.">
                <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                <button class="button button-danger" type="submit">Delete</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<section class="section-heading">
    <div>
        <h2>Ingestion jobs</h2>
        <p class="muted">Queue attempts and operational errors for this source.</p>
    </div>
</section>

<?php if ($jobs === []): ?>
    <div class="panel compact-panel muted">No ingestion jobs are associated with this source.</div>
<?php else: ?>
    <div class="table-card">
        <table>
            <thead><tr><th>Job</th><th>Version</th><th>Status</th><th>Attempts</th><th>Available</th><th>Last error</th></tr></thead>
            <tbody>
            <?php foreach ($jobs as $job): ?>
                <tr>
                    <td>#<?= $escape($job->id) ?></td>
                    <td><?= $escape($job->versionNumber) ?></td>
                    <td><span class="badge badge-<?= $escape($job->status->value) ?>"><?= $escape(ucfirst($job->status->value)) ?></span></td>
                    <td><?= $escape($job->attempts) ?> / <?= $escape($job->maxAttempts) ?></td>
                    <td><?= $escape($formatDate($job->availableAt)) ?></td>
                    <td class="error-cell"><?= $job->lastError === null ? '—' : $escape($job->lastError) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if (is_string($success) && $success !== ''): ?>
    <div class="alert alert-success" role="status"><?= $escape($success) ?></div>
<?php endif; ?>

<section class="panel metadata-panel">
    <div><span>Source ID</span><strong><?= $escape($source->id) ?></strong></div>
    <div><span>Status</span><strong><?= $escape(ucfirst($source->status->value)) ?></strong></div>
    <div><span>Active version</span><strong><?= $source->activeVersionId === null ? 'None yet' : $escape($source->activeVersionId) ?></strong></div>
    <div><span>Versions</span><strong><?= $escape($source->versionCount) ?></strong></div>
    <div><span>Updated</span><strong><?= $escape($formatDate($source->updatedAt)) ?></strong></div>
    <div><span>Deleted</span><strong><?= $escape($formatDate($source->deletedAt)) ?></strong></div>
</section>

<section class="section-heading">
    <div>
        <h2>Version history</h2>
        <p class="muted">Versions are immutable. A version becomes active only after extraction and chunking succeed.</p>
    </div>
</section>

<div class="version-list">
    <?php foreach ($versions as $version): ?>
        <article class="panel version-card">
            <div class="version-heading">
                <div>
                    <h3>Version <?= $escape($version->versionNumber) ?></h3>
                    <p><?= $escape($formatDate($version->createdAt)) ?></p>
                </div>
                <span class="badge badge-<?= $escape($version->processingStatus->value) ?>"><?= $escape(ucfirst($version->processingStatus->value)) ?></span>
            </div>
            <dl class="definition-grid">
                <?php if ($version->originalUrl !== null): ?>
                    <div><dt>Original URL</dt><dd><a class="text-link break-text" href="<?= $escape($version->originalUrl) ?>" rel="noreferrer" target="_blank"><?= $escape($version->originalUrl) ?></a></dd></div>
                <?php endif; ?>
                <?php if ($version->originalFilename !== null): ?>
                    <div><dt>Original filename</dt><dd><?= $escape($version->originalFilename) ?></dd></div>
                    <div><dt>MIME type</dt><dd><?= $escape($version->mimeType) ?></dd></div>
                    <div><dt>File size</dt><dd><?= $escape($formatBytes($version->fileSize)) ?></dd></div>
                    <div><dt>Stored path</dt><dd><code><?= $escape($version->storedFilePath) ?></code></dd></div>
                    <div class="definition-wide"><dt>File SHA-256</dt><dd><code class="break-text"><?= $escape($version->fileHash) ?></code></dd></div>
                <?php endif; ?>
                <div><dt>Chunks</dt><dd><?= $escape($version->chunkCount) ?></dd></div>
                <?php if ($version->contentHash !== null): ?>
                    <div class="definition-wide"><dt>Extracted content SHA-256</dt><dd><code class="break-text"><?= $escape($version->contentHash) ?></code></dd></div>
                <?php endif; ?>
                <div><dt>Processed</dt><dd><?= $escape($formatDate($version->processedAt)) ?></dd></div>
                <div><dt>Activated</dt><dd><?= $escape($formatDate($version->activatedAt)) ?></dd></div>
            </dl>
            <?php if ($version->errorMessage !== null): ?>
                <div class="alert alert-error"><?= $escape($version->errorMessage) ?></div>
            <?php endif; ?>
            <?php if ($version->extractedText !== null): ?>
                <details class="extraction-details">
                    <summary>View extracted text</summary>
                    <pre><?= $escape($version->extractedText) ?></pre>
                </details>
            <?php endif; ?>
            <?php if ($version->metadata !== []): ?>
                <details class="extraction-details">
                    <summary>View extracted metadata</summary>
                    <pre><?= $escape($formatJson($version->metadata)) ?></pre>
                </details>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
</div>
