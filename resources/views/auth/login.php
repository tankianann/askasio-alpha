<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $escape($title) ?> · RAG Server</title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="auth-body">
<main class="auth-shell">
    <section class="auth-card" aria-labelledby="login-heading">
        <div class="brand-mark" aria-hidden="true">R</div>
        <p class="eyebrow">RAG Server</p>
        <h1 id="login-heading">Administrator login</h1>
        <p class="muted">Sign in to manage knowledge sources and API access.</p>

        <?php if (is_string($error) && $error !== ''): ?>
            <div class="alert alert-error" role="alert"><?= $escape($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/admin/login" class="form-stack">
            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">

            <label for="username">Username</label>
            <input
                id="username"
                name="username"
                type="text"
                value="<?= $escape($username) ?>"
                required
                autofocus
                autocomplete="username"
                maxlength="64"
                spellcheck="false"
            >

            <label for="password">Password</label>
            <input
                id="password"
                name="password"
                type="password"
                required
                autocomplete="current-password"
            >

            <button type="submit" class="button button-primary">Sign in</button>
        </form>
    </section>
</main>
</body>
</html>
