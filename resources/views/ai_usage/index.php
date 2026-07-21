<div class="page-heading"><div><p class="eyebrow">Monitoring</p><h1>AI Usage</h1><p class="muted">Quota-managed embedding and model usage from API and chatbot requests, separated from authentication keys and request activity.</p></div></div>

<?php if (is_string($error) && $error !== ''): ?><div class="alert alert-error" role="alert"><?= $escape($error) ?></div><?php endif; ?>

<section class="metric-grid" aria-label="Installation AI usage limits">
    <article class="metric-card"><span>AI usage tokens today (UTC)</span><strong><?= $escape(number_format($quota->dailyConsumed)) ?><?= $quota->dailyLimit > 0 ? ' / ' . $escape(number_format($quota->dailyLimit)) : '' ?></strong><small><?= $escape(number_format($quota->dailyReserved)) ?> reserved · <?= $escape(number_format($todayUnattributed)) ?> unattributed</small></article>
    <article class="metric-card"><span>AI usage tokens this month (UTC)</span><strong><?= $escape(number_format($quota->monthlyConsumed)) ?><?= $quota->monthlyLimit > 0 ? ' / ' . $escape(number_format($quota->monthlyLimit)) : '' ?></strong><small><?= $escape(number_format($quota->monthlyReserved)) ?> reserved · <?= $escape(number_format($monthUnattributed)) ?> unattributed</small></article>
    <article class="metric-card"><span>Selected period</span><strong><?= $escape(number_format($report->usageTokens)) ?></strong><small><?= $escape(number_format($report->estimatedTokens)) ?> estimated across <?= $escape(number_format($report->requests)) ?> provider operations</small></article>
</section>
<p class="muted">Unattributed usage includes consumption recorded before access attribution was introduced. Reserved tokens are temporary capacity held for in-progress requests. Knowledge-ingestion embeddings are outside this request quota and are not included.</p>

<form class="panel filter-panel compact-filter-panel" method="get" action="/admin/ai-usage">
    <div class="filter-heading"><div><h2>Reporting window</h2><p>Select up to 90 inclusive UTC days.</p></div></div>
    <div class="filter-grid"><label class="filter-field"><span>From</span><input type="date" name="date_from" required value="<?= $escape($query->dateFrom) ?>"></label><label class="filter-field"><span>To</span><input type="date" name="date_to" required value="<?= $escape($query->dateTo) ?>"></label></div>
    <div class="filter-actions"><button class="button button-primary" type="submit">Apply</button></div>
</form>

<div class="section-heading"><h2>By access method</h2><p>These categories describe how the provider operation was authorized. They add up to the selected-period total.</p></div>
<?php if ($report->byAccessMethod === []): ?><section class="empty-state compact-empty"><h3>No attributed AI usage</h3><p>No provider operations were recorded in this window.</p></section><?php else: ?><div class="table-card"><table><thead><tr><th>Access method</th><th>Provider operations</th><th>AI usage tokens</th><th>Estimated</th></tr></thead><tbody><?php foreach ($report->byAccessMethod as $metric): ?><?php $method = \App\Domain\Api\ApiAccessMethod::from($metric['access_method']); ?><tr><td><strong><?= $escape($method->label()) ?></strong></td><td><?= $escape(number_format($metric['requests'])) ?></td><td><?= $escape(number_format($metric['usage_tokens'])) ?></td><td><?= $escape(number_format($metric['estimated_tokens'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>

<div class="section-heading"><h2>Daily usage</h2><p>Actual and estimated provider consumption reconciled from the quota reservation flow.</p></div>
<?php if ($report->daily === []): ?><section class="empty-state compact-empty"><h3>No daily usage</h3><p>No provider operations were recorded in this window.</p></section><?php else: ?><div class="table-card"><table><thead><tr><th>UTC day</th><th>AI usage tokens</th><th>Estimated</th></tr></thead><tbody><?php foreach ($report->daily as $metric): ?><tr><td><?= $escape($metric['day']) ?></td><td><?= $escape(number_format($metric['usage_tokens'])) ?></td><td><?= $escape(number_format($metric['estimated_tokens'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>

<?php foreach ([['Chatbots', 'Chatbot', $report->byChatbot], ['General API keys', 'General API key', $report->byGeneralApiKey], ['Chatbot API keys', 'Chatbot API key', $report->byChatbotApiKey]] as [$heading, $columnHeading, $metrics]): ?>
    <div class="section-heading"><h2><?= $escape($heading) ?></h2><p>Top 100 by AI usage in the selected period.</p></div>
    <?php if ($metrics === []): ?><section class="empty-state compact-empty"><h3>No attributed usage</h3></section><?php else: ?><div class="table-card"><table><thead><tr><th><?= $escape($columnHeading) ?></th><th>AI usage tokens</th><th>Estimated</th></tr></thead><tbody><?php foreach ($metrics as $metric): ?><tr><td><?= $escape($metric['name']) ?></td><td><?= $escape(number_format($metric['usage_tokens'])) ?></td><td><?= $escape(number_format($metric['estimated_tokens'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php endforeach; ?>
