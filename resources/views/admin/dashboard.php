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
