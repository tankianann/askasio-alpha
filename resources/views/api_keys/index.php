<div class="page-heading">
    <div>
        <p class="eyebrow">Access</p>
        <h1>API keys</h1>
        <p class="muted">Create and revoke bearer credentials for external applications.</p>
    </div>
    <a class="button button-primary" href="/admin/api-keys/create">Create API key</a>
</div>

<?php if (is_string($success) && $success !== ''): ?>
    <div class="alert alert-success" role="status"><?= $escape($success) ?></div>
<?php endif; ?>

<?php if ($keys === []): ?>
    <section class="empty-state">
        <div class="empty-icon" aria-hidden="true">+</div>
        <h2>No API keys yet</h2>
        <p>Create a key before connecting an external application to the retrieval API.</p>
        <a class="button button-primary" href="/admin/api-keys/create">Create your first API key</a>
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
