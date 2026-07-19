<div class="page-heading">
    <div>
        <p class="eyebrow">Operations</p>
        <h1>API Activity</h1>
        <p class="muted">Understand recent usage, authentication attempts, and response times. Questions and bearer secrets are never recorded.</p>
    </div>
</div>

<?php if ($logs === []): ?>
    <section class="empty-state">
        <div class="empty-icon" aria-hidden="true"><svg class="icon"><use href="/assets/icons.svg#icon-arrow-up-right"></use></svg></div>
        <h2>No API activity yet</h2>
        <p>Activity from applications connected to Ask Asio will appear here.</p>
    </section>
<?php else: ?>
    <div class="table-card">
        <table>
            <thead>
            <tr>
                <th>Date</th>
                <th>Connection</th>
                <th>Request</th>
                <th>Status</th>
                <th>Duration</th>
                <th>Usage</th>
                <th>Request ID</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?= $escape($formatDate($log->createdAt)) ?></td>
                    <td>
                        <?php if ($log->apiKeyId !== null): ?>
                            <?= $escape($log->apiKeyName ?? 'Deleted key') ?>
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
<?php endif; ?>
