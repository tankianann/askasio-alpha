<div class="page-heading">
    <div>
        <p class="eyebrow">Administration</p>
        <h1>Dashboard</h1>
        <p class="muted">The secure administrator area is ready.</p>
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
        <span class="status-dot status-dot-pending" aria-hidden="true"></span>
        <div>
            <h2>Knowledge sources</h2>
            <p>Source management will be added in Milestone 3.</p>
        </div>
    </article>
</section>
