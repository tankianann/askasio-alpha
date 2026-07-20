<div class="page-heading">
    <div><p class="eyebrow">Customer experience</p><h1>Chatbots</h1><p class="muted">Prepare, publish, and control grounded customer-facing assistants.</p></div>
    <a class="button button-primary" href="/admin/chatbots/create"><svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-plus"></use></svg><span>Create chatbot</span></a>
</div>

<?php if (is_string($success) && $success !== ''): ?><div class="alert alert-success" role="status"><?= $escape($success) ?></div><?php endif; ?>
<?php if (is_string($error) && $error !== ''): ?><div class="alert alert-error" role="alert"><?= $escape($error) ?></div><?php endif; ?>

<form class="panel filter-panel compact-filter-panel" method="get" action="/admin/chatbots">
    <?php if ($query->sort->value !== 'updated'): ?><input type="hidden" name="sort" value="<?= $escape($query->sort->value) ?>"><?php endif; ?>
    <?php if ($query->direction->value !== 'desc'): ?><input type="hidden" name="direction" value="<?= $escape($query->direction->value) ?>"><?php endif; ?>
    <div class="filter-heading"><div><h2>Find chatbots</h2><p>Search and filter server-side without loading complete configurations.</p></div><?php if ($query->hasActiveFilters()): ?><a class="text-link" href="/admin/chatbots">Clear filters</a><?php endif; ?></div>
    <div class="filter-grid">
        <label class="filter-field filter-field-wide"><span>Search</span><input type="search" name="search" value="<?= $escape($query->search) ?>" maxlength="190" placeholder="Name or public ID"></label>
        <label class="filter-field"><span>Assigned source</span><input type="search" name="source" value="<?= $escape($query->sourceSearch) ?>" maxlength="190" placeholder="Source name"></label>
        <label class="filter-field"><span>Status</span><select name="status"><option value="all">All statuses</option><?php foreach (\App\Domain\Chatbots\ChatbotStatus::cases() as $status): ?><option value="<?= $escape($status->value) ?>" <?= $query->status->value === $status->value ? 'selected' : '' ?>><?= $escape(ucfirst($status->value)) ?></option><?php endforeach; ?></select></label>
        <label class="filter-field"><span>Publication</span><select name="publication"><option value="all">All</option><option value="draft" <?= $query->publication->value === 'draft' ? 'selected' : '' ?>>Unpublished draft</option><option value="published" <?= $query->publication->value === 'published' ? 'selected' : '' ?>>Published</option></select></label>
        <label class="filter-field"><span>Model</span><select name="model"><option value="">All models</option><?php if ($providerConfigured): ?><option value="<?= $escape($provider->chatModel) ?>" <?= $query->model === $provider->chatModel ? 'selected' : '' ?>><?= $escape($provider->chatModel) ?></option><?php endif; ?></select></label>
        <label class="filter-field"><span>Results per page</span><select name="per_page"><?php foreach ($query->pagination->allowedPageSizes() as $size): ?><option value="<?= $escape($size) ?>" <?= $query->pagination->perPage === $size ? 'selected' : '' ?>><?= $escape($size) ?></option><?php endforeach; ?></select></label>
    </div>
    <div class="filter-actions"><button class="button button-primary" type="submit">Apply filters</button><a class="button button-quiet" href="/admin/chatbots">Clear</a></div>
</form>

<div class="result-toolbar"><p><?php if ($page->total === 0): ?>No chatbots<?php else: ?>Showing <strong><?= $escape($page->from()) ?>–<?= $escape($page->to()) ?></strong> of <strong><?= $escape($page->total) ?></strong> chatbots<?php endif; ?></p><span>Page <?= $escape($page->pageRequest->page) ?> of <?= $escape($page->totalPages()) ?></span></div>

<?php if ($page->items === []): ?>
    <section class="empty-state"><img class="empty-logo" src="/assets/images/ask-asio.svg" alt=""><?php if ($query->hasActiveFilters()): ?><h2>No chatbots match these filters</h2><p>Adjust or clear the filters to see more chatbots.</p><a class="button button-quiet" href="/admin/chatbots">Clear filters</a><?php else: ?><h2>Create your first chatbot</h2><p>Prepare a grounded assistant privately, then publish it when its sources and origins are ready.</p><a class="button button-primary" href="/admin/chatbots/create">Create chatbot</a><?php endif; ?></section>
<?php else: ?>
    <div class="table-card"><table><thead><tr>
        <?php foreach ([[\App\Domain\Chatbots\ChatbotListSort::Name, 'Name'], [\App\Domain\Chatbots\ChatbotListSort::Status, 'Status'], [\App\Domain\Chatbots\ChatbotListSort::Publication, 'Publication'], [null, 'Model'], [\App\Domain\Chatbots\ChatbotListSort::Updated, 'Updated']] as [$sort, $label]): ?><?php if ($sort === null): ?><th><?= $escape($label) ?></th><?php else: ?><th aria-sort="<?= $query->sort === $sort ? ($query->direction->value === 'asc' ? 'ascending' : 'descending') : 'none' ?>"><a class="sort-link" href="<?= $escape($sortUrl($sort)) ?>"><?= $escape($label) ?><span aria-hidden="true"><?= $query->sort === $sort ? ($query->direction->value === 'asc' ? '↑' : '↓') : '↕' ?></span></a></th><?php endif; ?><?php endforeach; ?>
    </tr></thead><tbody><?php foreach ($page->items as $chatbot): ?><tr class="<?= $chatbot->status === \App\Domain\Chatbots\ChatbotStatus::Archived ? 'row-muted' : '' ?>">
        <td><a class="table-link" href="/admin/chatbots/<?= $escape($chatbot->id) ?>/edit"><?= $escape($chatbot->name) ?></a><span class="table-subtitle"><code><?= $escape($chatbot->publicId) ?></code></span></td>
        <td><span class="badge badge-<?= $escape($chatbot->status->value) ?>"><?= $escape(ucfirst($chatbot->status->value)) ?></span></td>
        <td><?php if ($chatbot->isPublished()): ?><span class="badge badge-ready">Publication <?= $escape($chatbot->publicationNumber) ?></span><?php else: ?><span class="badge badge-pending">Draft only</span><?php endif; ?><span class="table-subtitle">Draft revision <?= $escape($chatbot->draftRevision) ?></span></td>
        <td><?= $escape($chatbot->chatModel ?? 'Installation default') ?></td><td><?= $escape($formatDate($chatbot->updatedAt)) ?></td>
    </tr><?php endforeach; ?></tbody></table></div>
    <?php require dirname(__DIR__) . '/partials/list_pagination.php'; ?>
<?php endif; ?>
