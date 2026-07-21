<div class="page-heading">
    <div>
        <p class="eyebrow">Knowledge</p>
        <h1>Knowledge Base</h1>
        <p class="muted">Manage the URLs, Markdown, and PDF content Ask Asio can learn from.</p>
    </div>
    <div class="page-heading-actions">
        <div class="split-button" data-split-button>
            <a class="button button-primary split-button-main" href="/admin/sources/create">
                <svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-plus"></use></svg>
                <span>Add knowledge</span>
            </a>
            <button
                class="button button-primary split-button-toggle"
                type="button"
                aria-expanded="false"
                aria-haspopup="menu"
                aria-controls="add-knowledge-menu"
                aria-label="More ways to add knowledge"
                data-split-button-toggle
            ><span class="split-button-chevron" aria-hidden="true"></span></button>
            <div class="split-button-menu" id="add-knowledge-menu" role="menu" data-split-button-menu hidden>
                <a href="/admin/sources/create" role="menuitem">
                    <strong>Add one source</strong>
                    <span>Choose a URL, Markdown file, or PDF.</span>
                </a>
                <a href="/admin/sources/bulk-markdown" role="menuitem">
                    <strong>Bulk upload Markdown</strong>
                    <span>Import multiple Markdown files in one batch.</span>
                </a>
            </div>
        </div>
    </div>
</div>

<?php if (is_string($success) && $success !== ''): ?><div class="alert alert-success" role="status"><?= $escape($success) ?></div><?php endif; ?>
<?php if (is_string($error) && $error !== ''): ?><div class="alert alert-error" role="alert"><?= $escape($error) ?></div><?php endif; ?>

<form class="panel filter-panel compact-filter-panel" method="get" action="/admin/sources">
    <?php if ($query->sort->value !== 'updated'): ?><input type="hidden" name="sort" value="<?= $escape($query->sort->value) ?>"><?php endif; ?>
    <?php if ($query->direction->value !== 'desc'): ?><input type="hidden" name="direction" value="<?= $escape($query->direction->value) ?>"><?php endif; ?>
    <div class="filter-heading">
        <div><h2>Find knowledge</h2><p>Search, filter, and sort without loading the full Knowledge Base.</p></div>
        <?php if ($query->hasActiveFilters()): ?><a class="text-link" href="/admin/sources">Clear filters</a><?php endif; ?>
    </div>
    <div class="filter-grid">
        <label class="filter-field filter-field-wide"><span>Search name</span><input type="search" name="search" value="<?= $escape($query->search) ?>" maxlength="190" placeholder="Document name"></label>
        <label class="filter-field"><span>Source type</span><select name="type"><option value="">All types</option><?php foreach (\App\Domain\Sources\SourceType::cases() as $type): ?><option value="<?= $escape($type->value) ?>" <?= $query->type === $type ? 'selected' : '' ?>><?= $escape($type->label()) ?></option><?php endforeach; ?></select></label>
        <label class="filter-field"><span>Availability</span><select name="availability"><option value="all">All records</option><option value="enabled" <?= $query->availability->value === 'enabled' ? 'selected' : '' ?>>Enabled</option><option value="disabled" <?= $query->availability->value === 'disabled' ? 'selected' : '' ?>>Disabled</option><option value="deleted" <?= $query->availability->value === 'deleted' ? 'selected' : '' ?>>Deleted</option></select></label>
        <label class="filter-field"><span>Processing state</span><select name="processing"><option value="">Any state</option><?php foreach (\App\Domain\Sources\ProcessingStatus::cases() as $status): ?><option value="<?= $escape($status->value) ?>" <?= $query->processingStatus === $status ? 'selected' : '' ?>><?= $escape(ucfirst($status->value)) ?></option><?php endforeach; ?></select></label>
        <label class="filter-field"><span>Results per page</span><select name="per_page"><?php foreach ($query->pagination->allowedPageSizes() as $size): ?><option value="<?= $escape($size) ?>" <?= $query->pagination->perPage === $size ? 'selected' : '' ?>><?= $escape($size) ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="filter-actions"><button class="button button-primary" type="submit">Apply filters</button><a class="button button-quiet" href="/admin/sources">Clear</a></div>
</form>

<div class="result-toolbar">
    <p>
        <?php if ($page->total === 0): ?>No documents
        <?php else: ?>Showing <strong><?= $escape($page->from()) ?>–<?= $escape($page->to()) ?></strong> of <strong><?= $escape($page->total) ?></strong> documents<?php endif; ?>
    </p>
    <span>Page <?= $escape($page->pageRequest->page) ?> of <?= $escape($page->totalPages()) ?></span>
</div>

<?php if ($page->items === []): ?>
    <section class="empty-state">
        <img class="empty-logo" src="/assets/images/ask-asio.svg" alt="">
        <?php if ($query->hasActiveFilters()): ?><h2>No documents match these filters</h2><p>Adjust or clear the filters to see more knowledge sources.</p><a class="button button-quiet" href="/admin/sources">Clear filters</a>
        <?php else: ?><h2>Your Knowledge Base is ready</h2><p>Add a URL, Markdown file, or PDF so Ask Asio has something to learn from.</p><a class="button button-primary" href="/admin/sources/create"><svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-plus"></use></svg><span>Add your first document</span></a><?php endif; ?>
    </section>
<?php else: ?>
    <div class="table-card"><table><thead><tr>
        <?php foreach ([[\App\Domain\Sources\SourceListSort::Name, 'Name'], [\App\Domain\Sources\SourceListSort::Type, 'Type'], [\App\Domain\Sources\SourceListSort::Availability, 'Availability'], [\App\Domain\Sources\SourceListSort::Processing, 'Processing'], [\App\Domain\Sources\SourceListSort::Revisions, 'Revisions'], [\App\Domain\Sources\SourceListSort::Updated, 'Updated']] as [$sort, $label]): ?>
            <th aria-sort="<?= $query->sort === $sort ? ($query->direction->value === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a class="sort-link" href="<?= $escape($sortUrl($sort)) ?>"><?= $escape($label) ?><span aria-hidden="true"><?= $query->sort === $sort ? ($query->direction->value === 'asc' ? '↑' : '↓') : '↕' ?></span></a></th>
        <?php endforeach; ?>
    </tr></thead><tbody>
    <?php foreach ($page->items as $source): ?><tr class="<?= $source->isDeleted() ? 'row-muted' : '' ?>">
        <td><a class="table-link" href="/admin/sources/<?= $escape($source->id) ?>"><?= $escape($source->name) ?></a><?php if ($source->isDeleted()): ?> <span class="badge badge-deleted">Deleted</span><?php endif; ?></td>
        <td><?= $escape($source->type->label()) ?></td>
        <td><span class="badge badge-<?= $escape($source->isDeleted() ? 'deleted' : $source->status->value) ?>"><?= $escape($source->isDeleted() ? 'Deleted' : ucfirst($source->status->value)) ?></span></td>
        <td>
            <?php if ($source->latestProcessingStatus === null): ?>—
            <?php else: ?><span class="badge badge-<?= $escape($source->latestProcessingStatus->value) ?>"><?= $escape(ucfirst($source->latestProcessingStatus->value)) ?></span><?php endif; ?>
        </td>
        <td><?= $escape($source->versionCount) ?></td><td><?= $escape($formatDate($source->updatedAt)) ?></td>
    </tr><?php endforeach; ?>
    </tbody></table></div>
    <?php require dirname(__DIR__) . '/partials/list_pagination.php'; ?>
<?php endif; ?>
