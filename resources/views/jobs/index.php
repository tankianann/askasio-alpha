<div class="page-heading">
    <div>
        <p class="eyebrow">Operations</p>
        <h1>Processing</h1>
        <p class="muted">Follow how Ask Asio extracts, prepares, and indexes your knowledge.</p>
    </div>
</div>

<?php if (!$pipelineAvailable): ?>
    <div class="alert alert-warning" role="status">
        The processing pipeline is unavailable. New items will remain pending until the configuration is repaired.
    </div>
<?php endif; ?>

<section class="metric-grid" aria-label="Processing totals">
    <?php foreach (['pending' => 'Pending', 'processing' => 'Processing', 'completed' => 'Completed', 'failed' => 'Failed'] as $key => $label): ?>
        <article class="metric-card">
            <span><?= $escape($label) ?></span>
            <strong><?= $escape($counts[$key]) ?></strong>
        </article>
    <?php endforeach; ?>
</section>

<?php if ($jobs === []): ?>
    <section class="empty-state">
        <img class="empty-logo" src="/assets/images/ask-asio.svg" alt="">
        <h2>Nothing to process yet</h2>
        <p>Add something to your Knowledge Base and its processing activity will appear here.</p>
        <a class="button button-primary" href="/admin/sources/create">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-plus"></use></svg>
            <span>Add knowledge</span>
        </a>
    </section>
<?php else: ?>
    <div class="table-card">
        <table>
            <thead>
            <tr>
                <th>Activity</th>
                <th>Document revision</th>
                <th>Status</th>
                <th>Attempts</th>
                <th>Available</th>
                <th>Last error</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($jobs as $job): ?>
                <tr>
                    <td>#<?= $escape($job->id) ?></td>
                    <td>
                        <a class="table-link" href="/admin/sources/<?= $escape($job->sourceId) ?>"><?= $escape($job->sourceName) ?></a>
                        <span class="table-subtitle">Revision <?= $escape($job->versionNumber) ?></span>
                    </td>
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
