<div class="page-heading">
    <div>
        <p class="eyebrow">Knowledge</p>
        <h1>Knowledge Base</h1>
        <p class="muted">Manage the URLs, Markdown, and PDF content Ask Archie can learn from.</p>
    </div>
    <a class="button button-primary" href="/admin/sources/create">
        <svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-plus"></use></svg>
        <span>Add knowledge</span>
    </a>
</div>

<?php if (is_string($success) && $success !== ''): ?>
    <div class="alert alert-success" role="status"><?= $escape($success) ?></div>
<?php endif; ?>

<?php if ($sources === []): ?>
    <section class="empty-state">
        <img class="empty-logo" src="/assets/images/ask-archie.svg" alt="">
        <h2>Your Knowledge Base is ready</h2>
        <p>Add a URL, Markdown file, or PDF so Ask Archie has something to learn from.</p>
        <a class="button button-primary" href="/admin/sources/create">
            <svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-plus"></use></svg>
            <span>Add your first document</span>
        </a>
    </section>
<?php else: ?>
    <div class="table-card">
        <table>
            <thead>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Availability</th>
                <th>Processing</th>
                <th>Revisions</th>
                <th>Updated</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($sources as $source): ?>
                <tr class="<?= $source->isDeleted() ? 'row-muted' : '' ?>">
                    <td>
                        <a class="table-link" href="/admin/sources/<?= $escape($source->id) ?>"><?= $escape($source->name) ?></a>
                        <?php if ($source->isDeleted()): ?><span class="badge badge-deleted">Deleted</span><?php endif; ?>
                    </td>
                    <td><?= $escape($source->type->label()) ?></td>
                    <td><span class="badge badge-<?= $escape($source->status->value) ?>"><?= $escape(ucfirst($source->status->value)) ?></span></td>
                    <td>
                        <?php if ($source->latestProcessingStatus !== null): ?>
                            <span class="badge badge-<?= $escape($source->latestProcessingStatus->value) ?>"><?= $escape(ucfirst($source->latestProcessingStatus->value)) ?></span>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= $escape($source->versionCount) ?></td>
                    <td><?= $escape($formatDate($source->updatedAt)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
