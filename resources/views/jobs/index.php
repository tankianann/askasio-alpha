<div class="page-heading">
    <div>
        <p class="eyebrow">Operations</p>
        <h1>Ingestion jobs</h1>
        <p class="muted">Monitor queued source-version processing and retry state.</p>
    </div>
</div>

<?php if (!$pipelineAvailable): ?>
    <div class="alert alert-warning" role="status">
        Jobs are safely queued. The extraction processor arrives in Milestone 5, so workers will not claim them yet.
    </div>
<?php endif; ?>

<section class="metric-grid" aria-label="Queue totals">
    <?php foreach (['pending' => 'Pending', 'processing' => 'Processing', 'completed' => 'Completed', 'failed' => 'Failed'] as $key => $label): ?>
        <article class="metric-card">
            <span><?= $escape($label) ?></span>
            <strong><?= $escape($counts[$key]) ?></strong>
        </article>
    <?php endforeach; ?>
</section>

<?php if ($jobs === []): ?>
    <section class="empty-state">
        <h2>No ingestion jobs yet</h2>
        <p>Adding a source creates its first ingestion job transactionally.</p>
        <a class="button button-primary" href="/admin/sources/create">Add source</a>
    </section>
<?php else: ?>
    <div class="table-card">
        <table>
            <thead>
            <tr>
                <th>Job</th>
                <th>Source version</th>
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
                        <span class="table-subtitle">Version <?= $escape($job->versionNumber) ?></span>
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
