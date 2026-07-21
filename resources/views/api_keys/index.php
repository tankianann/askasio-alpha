<div class="page-heading"><div><p class="eyebrow">Connections</p><h1>General API Keys</h1><p class="muted">Keys for applications calling the general retrieval and chat APIs. These keys do not use chatbot configuration.</p></div><a class="button button-primary" href="/admin/api-keys/create"><svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-plus"></use></svg><span>Create General API key</span></a></div>

<?php if (is_string($success) && $success !== ''): ?><div class="alert alert-success" role="status"><?= $escape($success) ?></div><?php endif; ?>
<?php if (is_string($filterError) && $filterError !== ''): ?><div class="alert alert-error" role="alert"><?= $escape($filterError) ?></div><?php endif; ?>

<form class="panel filter-panel compact-filter-panel" method="get" action="/admin/api-keys">
    <?php if ($query->sort->value !== 'created'): ?><input type="hidden" name="sort" value="<?= $escape($query->sort->value) ?>"><?php endif; ?>
    <?php if ($query->direction->value !== 'desc'): ?><input type="hidden" name="direction" value="<?= $escape($query->direction->value) ?>"><?php endif; ?>
    <div class="filter-heading"><div><h2>Find General API keys</h2><p>Search by key name or the safe visible prefix.</p></div><?php if ($query->hasActiveFilters()): ?><a class="text-link" href="/admin/api-keys">Clear filters</a><?php endif; ?></div>
    <div class="filter-grid">
        <label class="filter-field filter-field-wide"><span>Search</span><input type="search" name="search" value="<?= $escape($query->search) ?>" maxlength="190" placeholder="Key name or prefix"></label>
        <label class="filter-field"><span>Status</span><select name="status"><option value="all">All statuses</option><option value="active" <?= $query->status->value === 'active' ? 'selected' : '' ?>>Active</option><option value="revoked" <?= $query->status->value === 'revoked' ? 'selected' : '' ?>>Revoked</option><option value="expired" <?= $query->status->value === 'expired' ? 'selected' : '' ?>>Expired</option></select></label>
        <label class="filter-field"><span>Results per page</span><select name="per_page"><?php foreach ($query->pagination->allowedPageSizes() as $size): ?><option value="<?= $escape($size) ?>" <?= $query->pagination->perPage === $size ? 'selected' : '' ?>><?= $escape($size) ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="filter-actions"><button class="button button-primary" type="submit">Apply filters</button><a class="button button-quiet" href="/admin/api-keys">Clear</a></div>
</form>

<div class="result-toolbar">
    <p>
        <?php if ($page->total === 0): ?>No General API keys
        <?php else: ?>Showing <strong><?= $escape($page->from()) ?>–<?= $escape($page->to()) ?></strong> of <strong><?= $escape($page->total) ?></strong> General API keys<?php endif; ?>
    </p>
    <span>Page <?= $escape($page->pageRequest->page) ?> of <?= $escape($page->totalPages()) ?></span>
</div>

<?php if ($page->items === []): ?>
    <section class="empty-state"><img class="empty-logo" src="/assets/images/ask-asio.svg" alt=""><?php if ($query->hasActiveFilters()): ?><h2>No General API keys match these filters</h2><p>Adjust or clear the filters to see more keys.</p><a class="button button-quiet" href="/admin/api-keys">Clear filters</a><?php else: ?><h2>No General API keys yet</h2><p>Create a key for an application that calls the general retrieval or chat API.</p><a class="button button-primary" href="/admin/api-keys/create"><svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-plus"></use></svg><span>Create your first General API key</span></a><?php endif; ?></section>
<?php else: ?>
    <div class="table-card"><table><thead><tr>
        <?php foreach ([[\App\Domain\ApiKeys\ApiKeyListSort::Name, 'Name'], [null, 'Prefix'], [\App\Domain\ApiKeys\ApiKeyListSort::Status, 'Status'], [null, 'AI usage tokens'], [\App\Domain\ApiKeys\ApiKeyListSort::Created, 'Created'], [\App\Domain\ApiKeys\ApiKeyListSort::LastUsed, 'Last used'], [\App\Domain\ApiKeys\ApiKeyListSort::Expires, 'Expires']] as [$sort, $label]): ?><?php if ($sort === null): ?><th><?= $escape($label) ?></th><?php else: ?><th aria-sort="<?= $query->sort === $sort ? ($query->direction->value === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a class="sort-link" href="<?= $escape($sortUrl($sort)) ?>"><?= $escape($label) ?><span aria-hidden="true"><?= $query->sort === $sort ? ($query->direction->value === 'asc' ? '↑' : '↓') : '↕' ?></span></a></th><?php endif; ?><?php endforeach; ?><th>Actions</th>
    </tr></thead><tbody><?php foreach ($page->items as $key): ?><?php $displayStatus = $key->displayStatus(); ?><tr class="<?= $displayStatus !== 'active' ? 'row-muted' : '' ?>">
        <?php $keyQuota = $apiKeyQuotas[$key->id] ?? null; ?>
        <td><strong><?= $escape($key->name) ?></strong></td><td><code><?= $escape($key->visiblePrefix) ?>…</code></td><td><span class="badge badge-<?= $escape($displayStatus) ?>"><?= $escape(ucfirst($displayStatus)) ?></span></td>
        <td><?php if ($keyQuota instanceof \App\Domain\ProviderQuota\ProviderQuotaSnapshot): ?><small><strong><?= $escape(number_format($keyQuota->dailyConsumed)) ?></strong> today<?php if ($keyQuota->dailyReserved > 0): ?> + <?= $escape(number_format($keyQuota->dailyReserved)) ?> reserved<?php endif; ?><br><strong><?= $escape(number_format($keyQuota->monthlyConsumed)) ?></strong> this month</small><?php else: ?>—<?php endif; ?></td>
        <td><?= $escape($formatDate($key->createdAt)) ?></td><td><?= $escape($formatDate($key->lastUsedAt)) ?></td><td><?= $escape($formatDate($key->expiresAt)) ?></td>
        <td><div class="action-row"><?php if ($displayStatus === 'active'): ?><form method="post" action="/admin/api-keys/<?= $escape($key->id) ?>/revoke" data-confirm="Revoke this API key? Existing clients will immediately lose access."><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><button class="button button-quiet" type="submit">Revoke</button></form><?php endif; ?><form method="post" action="/admin/api-keys/<?= $escape($key->id) ?>/delete" data-confirm="Permanently delete this API key record? Request logs will retain no secret."><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><button class="button button-danger" type="submit">Delete</button></form></div></td>
    </tr><?php endforeach; ?></tbody></table></div>
    <?php require dirname(__DIR__) . '/partials/list_pagination.php'; ?>
<?php endif; ?>
