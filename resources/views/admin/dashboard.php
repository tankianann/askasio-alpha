<div class="page-heading">
    <div>
        <p class="eyebrow">Workspace</p>
        <h1>Overview</h1>
        <p class="muted">A clear view of Ask Asio’s knowledge, system health, and recent processing.</p>
    </div>
</div>

<section class="status-grid" aria-label="Application status">
    <article class="status-card">
        <span class="status-dot status-dot-ready" aria-hidden="true"></span>
        <div>
            <h2>Workspace access</h2>
            <p>Your secure session is active as <?= $escape($admin->username) ?>.</p>
        </div>
    </article>
    <article class="status-card">
        <span class="status-dot <?= $activeSourceCount > 0 ? 'status-dot-ready' : 'status-dot-pending' ?>" aria-hidden="true"></span>
        <div>
            <h2>Knowledge Base</h2>
            <p><strong><?= $escape($activeSourceCount) ?></strong> active document<?= $activeSourceCount === 1 ? '' : 's' ?> available to Ask Asio.</p>
            <p><a class="text-link" href="/admin/sources">Manage knowledge</a></p>
        </div>
    </article>
</section>

<section class="status-grid dashboard-jobs" aria-label="Processing status">
    <article class="status-card">
        <span class="status-dot <?= $pendingJobCount > 0 ? 'status-dot-pending' : 'status-dot-ready' ?>" aria-hidden="true"></span>
        <div>
            <h2>Waiting to process</h2>
            <p><strong><?= $escape($pendingJobCount) ?></strong> item<?= $pendingJobCount === 1 ? '' : 's' ?> waiting to be processed.</p>
            <p><a class="text-link" href="/admin/jobs">View processing</a></p>
        </div>
    </article>
    <article class="status-card">
        <span class="status-dot <?= $failedJobCount > 0 ? 'status-dot-failed' : 'status-dot-ready' ?>" aria-hidden="true"></span>
        <div>
            <h2>Needs attention</h2>
            <p><strong><?= $escape($failedJobCount) ?></strong> processing item<?= $failedJobCount === 1 ? '' : 's' ?> need<?= $failedJobCount === 1 ? 's' : '' ?> attention.</p>
        </div>
    </article>
</section>
