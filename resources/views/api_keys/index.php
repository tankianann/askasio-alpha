<div class="page-heading"><div><p class="eyebrow">Connections</p><h1>API Access</h1><p class="muted">Create and manage secure connections for applications that communicate with Ask Asio.</p></div><a class="button button-primary" href="/admin/api-keys/create"><svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-plus"></use></svg><span>Create connection</span></a></div>

<?php if (is_string($success) && $success !== ''): ?><div class="alert alert-success" role="status"><?= $escape($success) ?></div><?php endif; ?>
<?php if (is_string($filterError) && $filterError !== ''): ?><div class="alert alert-error" role="alert"><?= $escape($filterError) ?></div><?php endif; ?>

<?php if ($globalQuota instanceof \App\Domain\ProviderQuota\ProviderQuotaSnapshot): ?>
    <section class="metric-grid" aria-label="Global provider token usage">
        <article class="metric-card">
            <span>Provider tokens today (UTC)</span>
            <strong><?= $escape(number_format($globalQuota->dailyConsumed)) ?><?= $globalQuota->dailyLimit > 0 ? ' / ' . $escape(number_format($globalQuota->dailyLimit)) : '' ?></strong>
            <?php if ($apiKeysTotalQuota instanceof \App\Domain\ProviderQuota\ProviderQuotaSnapshot && $chatbotQuota instanceof \App\Domain\ProviderQuota\ProviderQuotaSnapshot): ?><small><?= $escape(number_format($apiKeysTotalQuota->dailyConsumed)) ?> API connections · <?= $escape(number_format($chatbotQuota->dailyConsumed)) ?> chatbots</small><?php endif; ?>
            <small><?= $escape(number_format($globalQuota->dailyReserved)) ?> reserved<?= $globalQuota->dailyLimit === 0 ? ' · unlimited' : '' ?></small>
        </article>
        <article class="metric-card">
            <span>Provider tokens this month (UTC)</span>
            <strong><?= $escape(number_format($globalQuota->monthlyConsumed)) ?><?= $globalQuota->monthlyLimit > 0 ? ' / ' . $escape(number_format($globalQuota->monthlyLimit)) : '' ?></strong>
            <?php if ($apiKeysTotalQuota instanceof \App\Domain\ProviderQuota\ProviderQuotaSnapshot && $chatbotQuota instanceof \App\Domain\ProviderQuota\ProviderQuotaSnapshot): ?><small><?= $escape(number_format($apiKeysTotalQuota->monthlyConsumed)) ?> API connections · <?= $escape(number_format($chatbotQuota->monthlyConsumed)) ?> chatbots</small><?php endif; ?>
            <small><?= $escape(number_format($globalQuota->monthlyReserved)) ?> reserved<?= $globalQuota->monthlyLimit === 0 ? ' · unlimited' : '' ?></small>
        </article>
    </section>
    <p class="muted">Global usage includes all API connections and customer-facing chatbot traffic. Connection totals include deleted connections and connections outside the current page or filters.</p>
<?php endif; ?>

<form class="panel filter-panel compact-filter-panel" method="get" action="/admin/api-keys">
    <?php if ($query->sort->value !== 'created'): ?><input type="hidden" name="sort" value="<?= $escape($query->sort->value) ?>"><?php endif; ?>
    <?php if ($query->direction->value !== 'desc'): ?><input type="hidden" name="direction" value="<?= $escape($query->direction->value) ?>"><?php endif; ?>
    <div class="filter-heading"><div><h2>Find connections</h2><p>Search by connection name or the safe visible key prefix.</p></div><?php if ($query->hasActiveFilters()): ?><a class="text-link" href="/admin/api-keys">Clear filters</a><?php endif; ?></div>
    <div class="filter-grid">
        <label class="filter-field filter-field-wide"><span>Search</span><input type="search" name="search" value="<?= $escape($query->search) ?>" maxlength="190" placeholder="Connection name or prefix"></label>
        <label class="filter-field"><span>Status</span><select name="status"><option value="all">All statuses</option><option value="active" <?= $query->status->value === 'active' ? 'selected' : '' ?>>Active</option><option value="revoked" <?= $query->status->value === 'revoked' ? 'selected' : '' ?>>Revoked</option><option value="expired" <?= $query->status->value === 'expired' ? 'selected' : '' ?>>Expired</option></select></label>
        <label class="filter-field"><span>Results per page</span><select name="per_page"><?php foreach ($query->pagination->allowedPageSizes() as $size): ?><option value="<?= $escape($size) ?>" <?= $query->pagination->perPage === $size ? 'selected' : '' ?>><?= $escape($size) ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="filter-actions"><button class="button button-primary" type="submit">Apply filters</button><a class="button button-quiet" href="/admin/api-keys">Clear</a></div>
</form>

<div class="result-toolbar">
    <p>
        <?php if ($page->total === 0): ?>No connections
        <?php else: ?>Showing <strong><?= $escape($page->from()) ?>–<?= $escape($page->to()) ?></strong> of <strong><?= $escape($page->total) ?></strong> connections<?php endif; ?>
    </p>
    <span>Page <?= $escape($page->pageRequest->page) ?> of <?= $escape($page->totalPages()) ?></span>
</div>

<?php if ($page->items === []): ?>
    <section class="empty-state"><img class="empty-logo" src="/assets/images/ask-asio.svg" alt=""><?php if ($query->hasActiveFilters()): ?><h2>No connections match these filters</h2><p>Adjust or clear the filters to see more API connections.</p><a class="button button-quiet" href="/admin/api-keys">Clear filters</a><?php else: ?><h2>No API connections yet</h2><p>Create secure API access before connecting an external application to Ask Asio.</p><a class="button button-primary" href="/admin/api-keys/create"><svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-plus"></use></svg><span>Create your first connection</span></a><?php endif; ?></section>
<?php else: ?>
    <div class="table-card"><table><thead><tr>
        <?php foreach ([[\App\Domain\ApiKeys\ApiKeyListSort::Name, 'Name'], [null, 'Prefix'], [\App\Domain\ApiKeys\ApiKeyListSort::Status, 'Status'], [null, 'Token usage'], [\App\Domain\ApiKeys\ApiKeyListSort::Created, 'Created'], [\App\Domain\ApiKeys\ApiKeyListSort::LastUsed, 'Last used'], [\App\Domain\ApiKeys\ApiKeyListSort::Expires, 'Expires']] as [$sort, $label]): ?><?php if ($sort === null): ?><th><?= $escape($label) ?></th><?php else: ?><th aria-sort="<?= $query->sort === $sort ? ($query->direction->value === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a class="sort-link" href="<?= $escape($sortUrl($sort)) ?>"><?= $escape($label) ?><span aria-hidden="true"><?= $query->sort === $sort ? ($query->direction->value === 'asc' ? '↑' : '↓') : '↕' ?></span></a></th><?php endif; ?><?php endforeach; ?><th>Actions</th>
    </tr></thead><tbody><?php foreach ($page->items as $key): ?><?php $displayStatus = $key->displayStatus(); ?><tr class="<?= $displayStatus !== 'active' ? 'row-muted' : '' ?>">
        <?php $keyQuota = $apiKeyQuotas[$key->id] ?? null; ?>
        <td><strong><?= $escape($key->name) ?></strong></td><td><code><?= $escape($key->visiblePrefix) ?>…</code></td><td><span class="badge badge-<?= $escape($displayStatus) ?>"><?= $escape(ucfirst($displayStatus)) ?></span></td>
        <td><?php if ($keyQuota instanceof \App\Domain\ProviderQuota\ProviderQuotaSnapshot): ?><small><strong><?= $escape(number_format($keyQuota->dailyConsumed)) ?></strong> today<?php if ($keyQuota->dailyReserved > 0): ?> + <?= $escape(number_format($keyQuota->dailyReserved)) ?> reserved<?php endif; ?><br><strong><?= $escape(number_format($keyQuota->monthlyConsumed)) ?></strong> this month</small><?php else: ?>—<?php endif; ?></td>
        <td><?= $escape($formatDate($key->createdAt)) ?></td><td><?= $escape($formatDate($key->lastUsedAt)) ?></td><td><?= $escape($formatDate($key->expiresAt)) ?></td>
        <td><div class="action-row"><?php if ($displayStatus === 'active'): ?><form method="post" action="/admin/api-keys/<?= $escape($key->id) ?>/revoke" data-confirm="Revoke this API key? Existing clients will immediately lose access."><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><button class="button button-quiet" type="submit">Revoke</button></form><?php endif; ?><form method="post" action="/admin/api-keys/<?= $escape($key->id) ?>/delete" data-confirm="Permanently delete this API key record? Request logs will retain no secret."><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><button class="button button-danger" type="submit">Delete</button></form></div></td>
    </tr><?php endforeach; ?></tbody></table></div>
    <?php require dirname(__DIR__) . '/partials/list_pagination.php'; ?>
<?php endif; ?>
