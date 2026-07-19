<div class="page-heading page-heading-compact">
    <div>
        <p class="breadcrumbs"><a href="<?= $escape($backUrl) ?>">API Activity</a> <span>/</span> Purge records</p>
        <h1>Purge API Activity</h1>
        <p class="muted">Review an exact record count before permanently deleting diagnostic activity.</p>
    </div>
</div>

<?php if (is_string($error) && $error !== ''): ?>
    <div class="alert alert-error" role="alert"><?= $escape($error) ?></div>
<?php endif; ?>

<section class="panel form-panel purge-scope-panel">
    <div class="section-heading flush-heading">
        <div>
            <p class="eyebrow">Step 1</p>
            <h2>Choose a purge scope</h2>
            <p class="muted">Nothing is deleted until the reviewed count is confirmed on this page.</p>
        </div>
    </div>

    <?php if ($activeFilters !== []): ?>
        <div class="purge-filter-context">
            <strong>Current API Activity filters</strong>
            <div class="filter-chip-list">
                <?php foreach ($activeFilters as $activeFilter): ?>
                    <span class="filter-chip"><?= $escape($activeFilter) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="<?= $escape($previewUrl) ?>">
        <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
        <fieldset class="purge-scope-grid">
            <legend class="visually-hidden">Purge scope</legend>

            <label class="purge-scope-option <?= $activeFilters === [] ? 'is-disabled' : '' ?>">
                <span class="purge-scope-title">
                    <input type="radio" name="scope" value="matching_filters"
                        <?= $selectedScope === 'matching_filters' ? 'checked' : '' ?>
                        <?= $activeFilters === [] ? 'disabled' : '' ?>>
                    Matching current filters
                </span>
                <span>Delete only records matching the filters carried over from API Activity.</span>
            </label>

            <label class="purge-scope-option">
                <span class="purge-scope-title">
                    <input type="radio" name="scope" value="older_than_age" <?= $selectedScope === 'older_than_age' ? 'checked' : '' ?>>
                    Older than an age
                </span>
                <span>Delete records older than a standard retention period.</span>
                <select name="age_days" aria-label="Purge records older than">
                    <?php foreach ([30, 90, 180, 365] as $age): ?>
                        <option value="<?= $escape($age) ?>" <?= (string) $selectedAge === (string) $age ? 'selected' : '' ?>><?= $escape($age) ?> days</option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="purge-scope-option">
                <span class="purge-scope-title">
                    <input type="radio" name="scope" value="before_date" <?= $selectedScope === 'before_date' ? 'checked' : '' ?>>
                    Older than a date
                </span>
                <span>Delete records created before midnight on the selected date.</span>
                <input type="date" name="before_date" value="<?= $escape($selectedDate) ?>" aria-label="Purge records created before date">
            </label>

            <label class="purge-scope-option purge-scope-all">
                <span class="purge-scope-title">
                    <input type="radio" name="scope" value="all" <?= $selectedScope === 'all' ? 'checked' : '' ?>>
                    All activity records
                </span>
                <span>Delete every API Activity record included in the reviewed snapshot.</span>
            </label>
        </fieldset>

        <div class="form-actions">
            <a class="button button-quiet" href="<?= $escape($backUrl) ?>">Cancel</a>
            <button class="button button-primary" type="submit">Review record count</button>
        </div>
    </form>
</section>

<?php if ($intent instanceof \App\Domain\Api\ApiRequestLogPurgeIntent): ?>
    <section class="panel form-panel danger-zone purge-confirmation" aria-labelledby="purge-confirmation-title">
        <div>
            <p class="eyebrow">Step 2</p>
            <h2 id="purge-confirmation-title">Confirm permanent deletion</h2>
            <p class="purge-record-count"><strong><?= $escape($intent->snapshot->recordCount) ?></strong> matching <?= $intent->snapshot->recordCount === 1 ? 'request' : 'requests' ?></p>
            <p><?= $escape($scopeDescription) ?></p>
            <p class="field-help">This count is frozen to records at or below log ID <?= $escape($intent->snapshot->maximumId ?? 'none') ?>. Requests recorded after this review will not be deleted.</p>
        </div>

        <?php if ($intent->snapshot->recordCount === 0): ?>
            <div class="alert alert-warning" role="status">There are no records in this scope. Choose another scope or return to API Activity.</div>
        <?php else: ?>
            <form method="post" action="/admin/api-requests/purge">
                <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="purge_token" value="<?= $escape($intent->token) ?>">
                <div class="form-group">
                    <label for="confirmation">Type <strong><?= $escape($intent->confirmationPhrase()) ?></strong> to confirm</label>
                    <input id="confirmation" name="confirmation" type="text" required autocomplete="off" spellcheck="false">
                    <p class="field-help">Deletion is permanent and cannot be undone from the application.</p>
                </div>
                <div class="form-actions">
                    <a class="button button-quiet" href="<?= $escape($backUrl) ?>">Cancel</a>
                    <button class="button button-danger" type="submit">Permanently delete records</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
