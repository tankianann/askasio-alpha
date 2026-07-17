<div class="page-heading">
    <div>
        <p class="eyebrow">Knowledge</p>
        <h1>Sources</h1>
        <p class="muted">Manage URLs, Markdown documents, and PDF documents.</p>
    </div>
    <a class="button button-primary" href="/admin/sources/create">Add source</a>
</div>

<?php if (is_string($success) && $success !== ''): ?>
    <div class="alert alert-success" role="status"><?= $escape($success) ?></div>
<?php endif; ?>

<?php if ($sources === []): ?>
    <section class="empty-state">
        <div class="empty-icon" aria-hidden="true">+</div>
        <h2>No knowledge sources yet</h2>
        <p>Add a URL, Markdown file, or PDF file to create its first pending version.</p>
        <a class="button button-primary" href="/admin/sources/create">Add your first source</a>
    </section>
<?php else: ?>
    <div class="table-card">
        <table>
            <thead>
            <tr>
                <th>Name</th>
                <th>Type</th>
                <th>Source status</th>
                <th>Latest processing</th>
                <th>Versions</th>
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
