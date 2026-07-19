<?php $paginationParameter = isset($pageParameter) && is_string($pageParameter) ? $pageParameter : 'page'; ?>
<?php $paginationLabel = isset($paginationAriaLabel) && is_string($paginationAriaLabel) ? $paginationAriaLabel : 'Result pages'; ?>
<?php if ($page->totalPages() > 1): ?>
    <nav class="pagination" aria-label="<?= $escape($paginationLabel) ?>">
        <div>
            <?php if ($page->previousPage() !== null): ?>
                <a class="pagination-link pagination-direction" rel="prev" href="<?= $escape($queryUrl([$paginationParameter => $page->previousPage() === 1 ? null : $page->previousPage()])) ?>">Previous</a>
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
                    <a class="pagination-link" href="<?= $escape($queryUrl([$paginationParameter => $pageNumber === 1 ? null : $pageNumber])) ?>"><?= $escape($pageNumber) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <div>
            <?php if ($page->nextPage() !== null): ?>
                <a class="pagination-link pagination-direction" rel="next" href="<?= $escape($queryUrl([$paginationParameter => $page->nextPage()])) ?>">Next</a>
            <?php else: ?>
                <span class="pagination-link pagination-direction is-disabled" aria-disabled="true">Next</span>
            <?php endif; ?>
        </div>
    </nav>
<?php endif; ?>
<?php unset($paginationParameter, $paginationLabel); ?>
