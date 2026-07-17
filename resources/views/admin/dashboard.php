<div class="page-heading">
    <div>
        <p class="eyebrow">Administration</p>
        <h1>Dashboard</h1>
        <p class="muted">Manage the knowledge sources used by your RAG application.</p>
    </div>
</div>

<section class="status-grid" aria-label="Application status">
    <article class="status-card">
        <span class="status-dot status-dot-ready" aria-hidden="true"></span>
        <div>
            <h2>Authentication</h2>
            <p>Session protection is active for <?= $escape($admin->username) ?>.</p>
        </div>
    </article>
    <article class="status-card">
        <span class="status-dot <?= $activeSourceCount > 0 ? 'status-dot-ready' : 'status-dot-pending' ?>" aria-hidden="true"></span>
        <div>
            <h2>Knowledge sources</h2>
            <p><strong><?= $escape($activeSourceCount) ?></strong> enabled source<?= $activeSourceCount === 1 ? '' : 's' ?>.</p>
            <p><a class="text-link" href="/admin/sources">Manage sources</a></p>
        </div>
    </article>
</section>

<section class="status-grid dashboard-jobs" aria-label="Ingestion queue">
    <article class="status-card">
        <span class="status-dot <?= $pendingJobCount > 0 ? 'status-dot-pending' : 'status-dot-ready' ?>" aria-hidden="true"></span>
        <div>
            <h2>Pending jobs</h2>
            <p><strong><?= $escape($pendingJobCount) ?></strong> waiting for ingestion.</p>
            <p><a class="text-link" href="/admin/jobs">View queue</a></p>
        </div>
    </article>
    <article class="status-card">
        <span class="status-dot <?= $failedJobCount > 0 ? 'status-dot-failed' : 'status-dot-ready' ?>" aria-hidden="true"></span>
        <div>
            <h2>Failed jobs</h2>
            <p><strong><?= $escape($failedJobCount) ?></strong> permanently failed.</p>
        </div>
    </article>
</section>
