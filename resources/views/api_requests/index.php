<div class="page-heading">
    <div>
        <p class="eyebrow">Operations</p>
        <h1>API Activity</h1>
        <p class="muted">Understand usage, authentication attempts, and response times. Questions and bearer secrets are never recorded.</p>
    </div>
    <a class="button button-quiet" href="<?= $escape($purgeUrl) ?>">Purge activity</a>
</div>

<?php if (is_string($success) && $success !== ''): ?>
    <div class="alert alert-success" role="status"><?= $escape($success) ?></div>
<?php endif; ?>
<?php if (is_string($filterError) && $filterError !== ''): ?>
    <div class="alert alert-error" role="alert"><?= $escape($filterError) ?></div>
<?php endif; ?>

<form class="panel filter-panel" method="get" action="/admin/api-requests">
    <?php if ($query->sort->value !== 'date'): ?>
        <input type="hidden" name="sort" value="<?= $escape($query->sort->value) ?>">
    <?php endif; ?>
    <?php if ($query->direction->value !== 'desc'): ?>
        <input type="hidden" name="direction" value="<?= $escape($query->direction->value) ?>">
    <?php endif; ?>

    <div class="filter-heading">
        <div>
            <h2>Filter activity</h2>
            <p>Filters are applied on the server and remain in the URL.</p>
        </div>
        <?php if ($query->hasActiveFilters()): ?>
            <a class="text-link" href="/admin/api-requests">Clear filters</a>
        <?php endif; ?>
    </div>

    <div class="filter-grid">
        <label class="filter-field">
            <span>From date</span>
            <input type="date" name="date_from" value="<?= $escape($query->dateFrom) ?>">
        </label>
        <label class="filter-field">
            <span>To date</span>
            <input type="date" name="date_to" value="<?= $escape($query->dateTo) ?>">
        </label>
        <label class="filter-field filter-field-wide">
            <span>Connection</span>
            <select name="api_key_id">
                <option value="">All connections</option>
                <?php foreach ($connections as $connection): ?>
                    <option value="<?= $escape($connection->id) ?>" <?= $query->apiKeyId === $connection->id ? 'selected' : '' ?>>
                        <?= $escape($connection->name) ?><?= $connection->visiblePrefix !== null ? ' · ' . $escape($connection->visiblePrefix) . '…' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filter-field filter-field-wide">
            <span>Endpoint</span>
            <input type="text" name="endpoint" value="<?= $escape($query->endpoint) ?>" placeholder="/api/v1/chat" maxlength="255">
        </label>
        <label class="filter-field">
            <span>Method</span>
            <select name="method">
                <option value="">Any method</option>
                <?php foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'] as $method): ?>
                    <option value="<?= $escape($method) ?>" <?= $query->method === $method ? 'selected' : '' ?>><?= $escape($method) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filter-field">
            <span>Status group</span>
            <select name="status_group">
                <option value="">Any status</option>
                <option value="success" <?= $query->statusGroup?->value === 'success' ? 'selected' : '' ?>>Success (2xx)</option>
                <option value="client_error" <?= $query->statusGroup?->value === 'client_error' ? 'selected' : '' ?>>Client error (4xx)</option>
                <option value="server_error" <?= $query->statusGroup?->value === 'server_error' ? 'selected' : '' ?>>Server error (5xx)</option>
            </select>
        </label>
        <label class="filter-field">
            <span>Exact status</span>
            <input type="number" name="status_code" value="<?= $escape($query->statusCode) ?>" min="100" max="599" placeholder="e.g. 429">
        </label>
        <label class="filter-field">
            <span>Authentication</span>
            <select name="authentication">
                <option value="all" <?= $query->authentication->value === 'all' ? 'selected' : '' ?>>All requests</option>
                <option value="authenticated" <?= $query->authentication->value === 'authenticated' ? 'selected' : '' ?>>Authenticated</option>
                <option value="unauthenticated" <?= $query->authentication->value === 'unauthenticated' ? 'selected' : '' ?>>Unauthenticated</option>
            </select>
        </label>
        <label class="filter-field">
            <span>Minimum duration (ms)</span>
            <input type="number" name="duration_min" value="<?= $escape($query->minimumDurationMilliseconds) ?>" min="0" max="4294967295">
        </label>
        <label class="filter-field">
            <span>Maximum duration (ms)</span>
            <input type="number" name="duration_max" value="<?= $escape($query->maximumDurationMilliseconds) ?>" min="0" max="4294967295">
        </label>
        <label class="filter-field filter-field-wide">
            <span>Request ID</span>
            <input type="text" name="request_id" value="<?= $escape($query->requestId) ?>" maxlength="64" autocomplete="off">
        </label>
        <label class="filter-field">
            <span>Results per page</span>
            <select name="per_page">
                <?php foreach ($query->pagination->allowedPageSizes() as $pageSize): ?>
                    <option value="<?= $escape($pageSize) ?>" <?= $query->pagination->perPage === $pageSize ? 'selected' : '' ?>><?= $escape($pageSize) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>

    <div class="filter-actions">
        <button class="button button-primary" type="submit">Apply filters</button>
        <a class="button button-quiet" href="/admin/api-requests">Clear</a>
    </div>
</form>

<?php if ($activeFilters !== []): ?>
    <section class="active-filter-summary" aria-label="Active filters">
        <strong>Active filters</strong>
        <div class="filter-chip-list">
            <?php foreach ($activeFilters as $activeFilter): ?>
                <span class="filter-chip"><?= $escape($activeFilter) ?></span>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<div class="result-toolbar">
    <p>
        <?php if ($page->total === 0): ?>
            No requests
        <?php else: ?>
            Showing <strong><?= $escape($page->from()) ?>–<?= $escape($page->to()) ?></strong> of <strong><?= $escape($page->total) ?></strong> requests
        <?php endif; ?>
    </p>
    <span>Page <?= $escape($page->pageRequest->page) ?> of <?= $escape($page->totalPages()) ?></span>
</div>

<?php if ($page->items === []): ?>
    <section class="empty-state">
        <div class="empty-icon" aria-hidden="true"><svg class="icon"><use href="/assets/icons.svg#icon-arrow-up-right"></use></svg></div>
        <?php if ($query->hasActiveFilters()): ?>
            <h2>No requests match these filters</h2>
            <p>Adjust or clear the current filters to see more API activity.</p>
            <a class="button button-quiet" href="/admin/api-requests">Clear filters</a>
        <?php else: ?>
            <h2>No API activity yet</h2>
            <p>Activity from applications connected to Ask Asio will appear here.</p>
        <?php endif; ?>
    </section>
<?php else: ?>
    <div class="table-card activity-table">
        <table>
            <thead>
            <tr>
                <th aria-sort="<?= $query->sort->value === 'date' ? ($query->direction->value === 'asc' ? 'ascending' : 'descending') : 'none' ?>">
                    <a class="sort-link" href="<?= $escape($sortUrl(\App\Domain\Api\ApiRequestLogSort::Date)) ?>">Date<span aria-hidden="true"><?= $query->sort->value === 'date' ? ($query->direction->value === 'asc' ? '↑' : '↓') : '↕' ?></span></a>
                </th>
                <th aria-sort="<?= $query->sort->value === 'connection' ? ($query->direction->value === 'asc' ? 'ascending' : 'descending') : 'none' ?>">
                    <a class="sort-link" href="<?= $escape($sortUrl(\App\Domain\Api\ApiRequestLogSort::Connection)) ?>">Connection<span aria-hidden="true"><?= $query->sort->value === 'connection' ? ($query->direction->value === 'asc' ? '↑' : '↓') : '↕' ?></span></a>
                </th>
                <th aria-sort="<?= $query->sort->value === 'endpoint' ? ($query->direction->value === 'asc' ? 'ascending' : 'descending') : 'none' ?>">
                    <a class="sort-link" href="<?= $escape($sortUrl(\App\Domain\Api\ApiRequestLogSort::Endpoint)) ?>">Request<span aria-hidden="true"><?= $query->sort->value === 'endpoint' ? ($query->direction->value === 'asc' ? '↑' : '↓') : '↕' ?></span></a>
                </th>
                <th aria-sort="<?= $query->sort->value === 'status' ? ($query->direction->value === 'asc' ? 'ascending' : 'descending') : 'none' ?>">
                    <a class="sort-link" href="<?= $escape($sortUrl(\App\Domain\Api\ApiRequestLogSort::Status)) ?>">Status<span aria-hidden="true"><?= $query->sort->value === 'status' ? ($query->direction->value === 'asc' ? '↑' : '↓') : '↕' ?></span></a>
                </th>
                <th aria-sort="<?= $query->sort->value === 'duration' ? ($query->direction->value === 'asc' ? 'ascending' : 'descending') : 'none' ?>">
                    <a class="sort-link" href="<?= $escape($sortUrl(\App\Domain\Api\ApiRequestLogSort::Duration)) ?>">Duration<span aria-hidden="true"><?= $query->sort->value === 'duration' ? ($query->direction->value === 'asc' ? '↑' : '↓') : '↕' ?></span></a>
                </th>
                <th>Usage</th>
                <th>Request ID</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($page->items as $log): ?>
                <tr>
                    <td><?= $escape($formatDate($log->createdAt)) ?></td>
                    <td>
                        <?php if ($log->apiKeyId !== null): ?>
                            <?= $escape($log->apiKeyName ?? 'Deleted connection') ?>
                            <?php if ($log->apiKeyPrefix !== null): ?><span class="table-subtitle"><code><?= $escape($log->apiKeyPrefix) ?>…</code></span><?php endif; ?>
                        <?php else: ?>
                            <span class="muted">Unauthenticated</span>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= $escape($log->method) ?></strong> <code><?= $escape($log->endpoint) ?></code></td>
                    <td>
                        <span class="badge <?= $log->statusCode >= 400 ? 'badge-failed' : 'badge-ready' ?>"><?= $escape($log->statusCode) ?></span>
                        <?php if ($log->errorCategory !== null): ?><span class="table-subtitle"><?= $escape($log->errorCategory) ?></span><?php endif; ?>
                    </td>
                    <td><?= $escape($log->durationMilliseconds) ?> ms</td>
                    <td><?= $log->usage === [] ? '—' : $escape($formatJson($log->usage)) ?></td>
                    <td><code><?= $escape($log->requestId) ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($page->totalPages() > 1): ?>
        <nav class="pagination" aria-label="API Activity pages">
            <div>
                <?php if ($page->previousPage() !== null): ?>
                    <a class="pagination-link pagination-direction" rel="prev" href="<?= $escape($queryUrl(['page' => $page->previousPage() === 1 ? null : $page->previousPage()])) ?>">Previous</a>
                <?php else: ?>
                    <span class="pagination-link pagination-direction is-disabled" aria-disabled="true">Previous</span>
                <?php endif; ?>
            </div>
            <div class="pagination-pages">
                <?php foreach ($page->pageWindow() as $pageNumber): ?>
                    <?php if ($pageNumber === null): ?>
                        <span class="pagination-ellipsis" aria-hidden="true">…</span>
                    <?php elseif ($pageNumber === $page->pageRequest->page): ?>
                        <span class="pagination-link is-current" aria-current="page"><?= $escape($pageNumber) ?></span>
                    <?php else: ?>
                        <a class="pagination-link" href="<?= $escape($queryUrl(['page' => $pageNumber === 1 ? null : $pageNumber])) ?>"><?= $escape($pageNumber) ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <div>
                <?php if ($page->nextPage() !== null): ?>
                    <a class="pagination-link pagination-direction" rel="next" href="<?= $escape($queryUrl(['page' => $page->nextPage()])) ?>">Next</a>
                <?php else: ?>
                    <span class="pagination-link pagination-direction is-disabled" aria-disabled="true">Next</span>
                <?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>
<?php endif; ?>
