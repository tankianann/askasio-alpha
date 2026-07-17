<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $escape($title) ?> · RAG Server</title>
    <link rel="stylesheet" href="/assets/app.css">
    <script src="/assets/app.js" defer></script>
</head>
<body class="admin-body">
<header class="topbar">
    <a class="brand" href="/admin" aria-label="RAG Server dashboard">
        <span class="brand-mark" aria-hidden="true">R</span>
        <span>RAG Server</span>
    </a>
    <div class="account-menu">
        <span class="account-name"><?= $escape($admin->username) ?></span>
        <form method="post" action="/admin/logout">
            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
            <button type="submit" class="button button-quiet">Log out</button>
        </form>
    </div>
</header>

<div class="admin-shell">
    <aside class="sidebar" aria-label="Administration">
        <nav>
            <a href="/admin" <?= $currentSection === 'dashboard' ? 'aria-current="page"' : '' ?>>Dashboard</a>
            <a href="/admin/sources" <?= $currentSection === 'sources' ? 'aria-current="page"' : '' ?>>Sources</a>
        </nav>
        <div class="environment-badge"><?= $escape($environment) ?></div>
    </aside>
    <main class="admin-main">
        <?= $content ?>
    </main>
</div>
</body>
</html>
