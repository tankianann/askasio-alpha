<div class="page-heading"><div><p class="eyebrow">Operations</p><h1>Processing</h1><p class="muted">Follow how Ask Asio extracts, prepares, and indexes your knowledge.</p></div></div>

<?php if (!$pipelineAvailable): ?><div class="alert alert-warning" role="status">The processing pipeline is unavailable. New items will remain pending until the configuration is repaired.</div><?php endif; ?>
<?php if (is_string($filterError) && $filterError !== ''): ?><div class="alert alert-error" role="alert"><?= $escape($filterError) ?></div><?php endif; ?>

<section class="metric-grid" aria-label="Processing totals"><?php foreach (['pending' => 'Pending', 'processing' => 'Processing', 'completed' => 'Completed', 'failed' => 'Failed'] as $key => $label): ?><article class="metric-card"><span><?= $escape($label) ?></span><strong><?= $escape($counts[$key]) ?></strong></article><?php endforeach; ?></section>

<form class="panel filter-panel compact-filter-panel" method="get" action="/admin/jobs">
    <?php if ($query->sort->value !== 'date'): ?><input type="hidden" name="sort" value="<?= $escape($query->sort->value) ?>"><?php endif; ?>
    <?php if ($query->direction->value !== 'desc'): ?><input type="hidden" name="direction" value="<?= $escape($query->direction->value) ?>"><?php endif; ?>
    <div class="filter-heading"><div><h2>Filter processing</h2><p>Metrics remain global while the table reflects these filters.</p></div><?php if ($query->hasActiveFilters()): ?><a class="text-link" href="/admin/jobs">Clear filters</a><?php endif; ?></div>
    <div class="filter-grid">
        <label class="filter-field"><span>Status</span><select name="status"><option value="">Any status</option><?php foreach (\App\Domain\Ingestion\JobStatus::cases() as $status): ?><option value="<?= $escape($status->value) ?>" <?= $query->status === $status ? 'selected' : '' ?>><?= $escape(ucfirst($status->value)) ?></option><?php endforeach; ?></select></label>
        <label class="filter-field filter-field-wide"><span>Knowledge source</span><select name="source_id"><option value="">All sources</option><?php foreach ($sources as $source): ?><option value="<?= $escape($source->id) ?>" <?= $query->sourceId === $source->id ? 'selected' : '' ?>><?= $escape($source->name) ?></option><?php endforeach; ?></select></label>
        <label class="filter-field"><span>From date</span><input type="date" name="date_from" value="<?= $escape($query->dateFrom) ?>"></label>
        <label class="filter-field"><span>To date</span><input type="date" name="date_to" value="<?= $escape($query->dateTo) ?>"></label>
        <label class="filter-field"><span>Minimum attempts</span><input type="number" name="attempts_min" value="<?= $escape($query->minimumAttempts) ?>" min="0" max="100"></label>
        <label class="filter-field"><span>Maximum attempts</span><input type="number" name="attempts_max" value="<?= $escape($query->maximumAttempts) ?>" min="0" max="100"></label>
        <label class="filter-field"><span>Results per page</span><select name="per_page"><?php foreach ($query->pagination->allowedPageSizes() as $size): ?><option value="<?= $escape($size) ?>" <?= $query->pagination->perPage === $size ? 'selected' : '' ?>><?= $escape($size) ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="filter-actions"><button class="button button-primary" type="submit">Apply filters</button><a class="button button-quiet" href="/admin/jobs">Clear</a></div>
</form>

<div class="result-toolbar">
    <p>
        <?php if ($page->total === 0): ?>No processing records
        <?php else: ?>Showing <strong><?= $escape($page->from()) ?>–<?= $escape($page->to()) ?></strong> of <strong><?= $escape($page->total) ?></strong> processing records<?php endif; ?>
    </p>
    <span>Page <?= $escape($page->pageRequest->page) ?> of <?= $escape($page->totalPages()) ?></span>
</div>

<?php if ($page->items === []): ?>
    <section class="empty-state"><img class="empty-logo" src="/assets/images/ask-asio.svg" alt=""><?php if ($query->hasActiveFilters()): ?><h2>No processing records match</h2><p>Adjust or clear the filters to see more activity.</p><a class="button button-quiet" href="/admin/jobs">Clear filters</a><?php else: ?><h2>Nothing to process yet</h2><p>Add something to your Knowledge Base and its processing activity will appear here.</p><a class="button button-primary" href="/admin/sources/create"><svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-plus"></use></svg><span>Add knowledge</span></a><?php endif; ?></section>
<?php else: ?>
    <div class="table-card"><table><thead><tr>
        <th>Activity</th>
        <?php foreach ([[\App\Domain\Ingestion\IngestionJobListSort::Source, 'Document revision'], [\App\Domain\Ingestion\IngestionJobListSort::Status, 'Status'], [\App\Domain\Ingestion\IngestionJobListSort::Attempts, 'Attempts'], [\App\Domain\Ingestion\IngestionJobListSort::Available, 'Available'], [\App\Domain\Ingestion\IngestionJobListSort::Date, 'Created']] as [$sort, $label]): ?><th aria-sort="<?= $query->sort === $sort ? ($query->direction->value === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a class="sort-link" href="<?= $escape($sortUrl($sort)) ?>"><?= $escape($label) ?><span aria-hidden="true"><?= $query->sort === $sort ? ($query->direction->value === 'asc' ? '↑' : '↓') : '↕' ?></span></a></th><?php endforeach; ?>
        <th>Last error</th>
    </tr></thead><tbody><?php foreach ($page->items as $job): ?><tr>
        <td>#<?= $escape($job->id) ?></td>
        <td><a class="table-link" href="/admin/sources/<?= $escape($job->sourceId) ?>"><?= $escape($job->sourceName) ?></a><span class="table-subtitle">Revision <?= $escape($job->versionNumber) ?></span></td>
        <td><span class="badge badge-<?= $escape($job->status->value) ?>"><?= $escape(ucfirst($job->status->value)) ?></span></td>
        <td><?= $escape($job->attempts) ?> / <?= $escape($job->maxAttempts) ?></td><td><?= $escape($formatDate($job->availableAt)) ?></td><td><?= $escape($formatDate($job->createdAt)) ?></td><td class="error-cell"><?= $job->lastError === null ? '—' : $escape($job->lastError) ?></td>
    </tr><?php endforeach; ?></tbody></table></div>
    <?php require dirname(__DIR__) . '/partials/list_pagination.php'; ?>
<?php endif; ?>
