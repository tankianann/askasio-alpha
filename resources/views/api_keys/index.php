<div class="page-heading">
    <div>
        <p class="eyebrow">Connections</p>
        <h1>API Access</h1>
        <p class="muted">Create and manage secure connections for applications that communicate with Ask Archie.</p>
    </div>
    <a class="button button-primary" href="/admin/api-keys/create">
        <svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-plus"></use></svg>
        <span>Create connection</span>
    </a>
</div>

<?php if (is_string($success) && $success !== ''): ?>
    <div class="alert alert-success" role="status"><?= $escape($success) ?></div>
<?php endif; ?>

<?php if ($keys === []): ?>
    <section class="empty-state">
        <img class="empty-logo" src="/assets/images/ask-archie.svg" alt="">
        <h2>No API connections yet</h2>
        <p>Create secure API access before connecting an external application to Ask Archie.</p>
        <a class="button button-primary" href="/admin/api-keys/create">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-plus"></use></svg>
            <span>Create your first connection</span>
        </a>
    </section>
<?php else: ?>
    <div class="table-card">
        <table>
            <thead>
            <tr>
                <th>Name</th>
                <th>Prefix</th>
                <th>Status</th>
                <th>Created</th>
                <th>Last used</th>
                <th>Expires</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($keys as $key): ?>
                <?php $displayStatus = $key->displayStatus(); ?>
                <tr class="<?= $displayStatus !== 'active' ? 'row-muted' : '' ?>">
                    <td><strong><?= $escape($key->name) ?></strong></td>
                    <td><code><?= $escape($key->visiblePrefix) ?>…</code></td>
                    <td><span class="badge badge-<?= $escape($displayStatus) ?>"><?= $escape(ucfirst($displayStatus)) ?></span></td>
                    <td><?= $escape($formatDate($key->createdAt)) ?></td>
                    <td><?= $escape($formatDate($key->lastUsedAt)) ?></td>
                    <td><?= $escape($formatDate($key->expiresAt)) ?></td>
                    <td>
                        <div class="action-row">
                            <?php if ($displayStatus === 'active'): ?>
                                <form method="post" action="/admin/api-keys/<?= $escape($key->id) ?>/revoke" data-confirm="Revoke this API key? Existing clients will immediately lose access.">
                                    <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                                    <button class="button button-quiet" type="submit">Revoke</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="/admin/api-keys/<?= $escape($key->id) ?>/delete" data-confirm="Permanently delete this API key record? Request logs will retain no secret.">
                                <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                                <button class="button button-danger" type="submit">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
