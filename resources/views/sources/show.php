<div class="page-heading page-heading-compact">
    <div>
        <p class="breadcrumbs"><a href="/admin/sources">Knowledge Base</a> <span>/</span> <?= $escape($source->name) ?></p>
        <div class="title-row">
            <h1><?= $escape($source->name) ?></h1>
            <?php if ($source->isDeleted()): ?><span class="badge badge-deleted">Deleted</span><?php endif; ?>
        </div>
        <p class="muted"><?= $escape($source->type->label()) ?> document · Added <?= $escape($formatDate($source->createdAt)) ?></p>
    </div>
    <?php if (!$source->isDeleted()): ?>
        <div class="action-row">
            <?php if ($source->type->value === 'url'): ?>
                <form method="post" action="/admin/sources/<?= $escape($source->id) ?>/refresh" data-confirm="Fetch this URL and create a new revision?">
                    <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                    <button class="button button-primary" type="submit">Refresh URL</button>
                </form>
            <?php else: ?>
                <a class="button button-primary" href="/admin/sources/<?= $escape($source->id) ?>/replace">Upload replacement</a>
            <?php endif; ?>
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
            <form method="post" action="/admin/sources/<?= $escape($source->id) ?>/delete" data-confirm="Move this document to deleted items? Its revisions and files will be preserved.">
                <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                <button class="button button-danger" type="submit">Delete</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<section class="section-heading">
    <div>
        <h2>Processing history</h2>
        <p class="muted">Review processing attempts and any operational issues for this document.</p>
    </div>
</section>

<div class="result-toolbar">
    <p><?= $jobsPage->total === 0 ? 'No processing records' : 'Showing <strong>' . $escape($jobsPage->from()) . '–' . $escape($jobsPage->to()) . '</strong> of <strong>' . $escape($jobsPage->total) . '</strong> processing records' ?></p>
    <span>Page <?= $escape($jobsPage->pageRequest->page) ?> of <?= $escape($jobsPage->totalPages()) ?></span>
</div>

<?php if ($jobsPage->items === []): ?>
    <div class="panel compact-panel muted">No processing activity is associated with this document.</div>
<?php else: ?>
    <div class="table-card">
        <table>
            <thead><tr><th>Activity</th><th>Revision</th><th>Status</th><th>Attempts</th><th>Available</th><th>Last error</th></tr></thead>
            <tbody>
            <?php foreach ($jobsPage->items as $job): ?>
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
    <?php
        $page = $jobsPage;
        $pageParameter = 'job_page';
        $paginationAriaLabel = 'Processing history pages';
        $queryUrl = $historyUrl;
        require dirname(__DIR__) . '/partials/list_pagination.php';
        unset($page, $pageParameter, $paginationAriaLabel, $queryUrl);
    ?>
<?php endif; ?>

<?php if (is_string($success) && $success !== ''): ?>
    <div class="alert alert-success" role="status"><?= $escape($success) ?></div>
<?php endif; ?>
<?php if (is_string($error) && $error !== ''): ?>
    <div class="alert alert-error" role="alert"><?= $escape($error) ?></div>
<?php endif; ?>

<?php if ($source->isDeleted()): ?>
    <section class="panel form-panel danger-zone">
        <h2>Permanently delete document</h2>
        <p>This irreversibly removes every revision, content segment, embedding, processing record, and stored file.</p>
        <form method="post" action="/admin/sources/<?= $escape($source->id) ?>/permanent-delete">
            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
            <div class="form-group">
                <label for="confirmation">Type <strong><?= $escape($source->name) ?></strong> to confirm</label>
                <input id="confirmation" name="confirmation" type="text" required autocomplete="off">
            </div>
            <button class="button button-danger" type="submit">Permanently delete</button>
        </form>
    </section>
<?php endif; ?>

<section class="panel metadata-panel">
    <div><span>Document ID</span><strong><?= $escape($source->id) ?></strong></div>
    <div><span>Status</span><strong><?= $escape(ucfirst($source->status->value)) ?></strong></div>
    <div><span>Active revision</span><strong><?= $source->activeVersionId === null ? 'None yet' : $escape($source->activeVersionId) ?></strong></div>
    <div><span>Revisions</span><strong><?= $escape($source->versionCount) ?></strong></div>
    <div><span>Updated</span><strong><?= $escape($formatDate($source->updatedAt)) ?></strong></div>
    <div><span>Deleted</span><strong><?= $escape($formatDate($source->deletedAt)) ?></strong></div>
</section>

<section class="section-heading">
    <div>
        <h2>Revision history</h2>
        <p class="muted">Revisions are preserved for reliability. A revision becomes active only after its content is processed successfully.</p>
    </div>
</section>

<div class="result-toolbar">
    <p><?= $versionsPage->total === 0 ? 'No revisions' : 'Showing <strong>' . $escape($versionsPage->from()) . '–' . $escape($versionsPage->to()) . '</strong> of <strong>' . $escape($versionsPage->total) . '</strong> revisions' ?></p>
    <span>Page <?= $escape($versionsPage->pageRequest->page) ?> of <?= $escape($versionsPage->totalPages()) ?></span>
</div>

<div class="version-list">
    <?php foreach ($versionsPage->items as $version): ?>
        <article class="panel version-card">
            <div class="version-heading">
                <div>
                    <h3>Revision <?= $escape($version->versionNumber) ?></h3>
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
                <div><dt>Content segments</dt><dd><?= $escape($version->chunkCount) ?></dd></div>
                <?php if ($version->contentHash !== null): ?>
                    <div class="definition-wide"><dt>Extracted content SHA-256</dt><dd><code class="break-text"><?= $escape($version->contentHash) ?></code></dd></div>
                <?php endif; ?>
                <div><dt>Processed</dt><dd><?= $escape($formatDate($version->processedAt)) ?></dd></div>
                <div><dt>Activated</dt><dd><?= $escape($formatDate($version->activatedAt)) ?></dd></div>
            </dl>
            <?php if ($version->errorMessage !== null): ?>
                <div class="alert alert-error"><?= $escape($version->errorMessage) ?></div>
            <?php endif; ?>
            <div class="action-row">
                <a class="button button-quiet" href="<?= $escape($versionUrl($version->id)) ?>">View revision details</a>
            <?php if (!$source->isDeleted() && !in_array($version->processingStatus->value, ['pending', 'processing'], true)): ?>
                <form method="post" action="/admin/sources/<?= $escape($source->id) ?>/versions/<?= $escape($version->id) ?>/reprocess" data-confirm="Create a new revision from revision <?= $escape($version->versionNumber) ?> and process it again?">
                    <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                    <button class="button button-quiet" type="submit">Reprocess as new revision</button>
                </form>
            <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</div>

<?php if ($versionsPage->items !== []): ?>
    <?php
        $page = $versionsPage;
        $pageParameter = 'revision_page';
        $paginationAriaLabel = 'Revision history pages';
        $queryUrl = $historyUrl;
        require dirname(__DIR__) . '/partials/list_pagination.php';
        unset($page, $pageParameter, $paginationAriaLabel, $queryUrl);
    ?>
<?php endif; ?>
