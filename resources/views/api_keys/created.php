<div class="page-heading page-heading-compact">
    <div>
        <p class="breadcrumbs"><a href="/admin/api-keys">General API Keys</a> <span>/</span> Created</p>
        <h1>General API key created</h1>
        <p class="muted"><?= $escape($created->apiKey->name) ?></p>
    </div>
</div>

<div class="alert alert-warning" role="alert">
    Copy this key now. It cannot be displayed again because only its SHA-256 hash is stored.
</div>

<section class="panel form-panel key-reveal">
    <div class="form-group">
        <label for="created-api-key">Bearer API key</label>
        <input id="created-api-key" type="text" value="<?= $escape($created->plaintextKey) ?>" readonly autocomplete="off" spellcheck="false">
    </div>
    <div class="form-actions">
        <a class="button button-quiet" href="/admin/api-keys">Done</a>
        <button class="button button-primary" type="button" data-copy-target="created-api-key">Copy key</button>
    </div>
</section>
