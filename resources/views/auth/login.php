<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#f4f7fa">
    <title><?= $escape($title) ?> · Ask Asio</title>
    <link rel="icon" href="/assets/images/ask-asio.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/app.css?v=18">
</head>
<body class="auth-body">
<main class="auth-shell">
    <section class="auth-card" aria-labelledby="login-heading">
        <div class="auth-brand">
            <img src="/assets/images/ask-asio.svg" alt="">
            <span>Ask Asio</span>
        </div>
        <p class="eyebrow">Welcome back</p>
        <h1 id="login-heading">Sign in</h1>
        <p class="muted">Manage your knowledge, processing, and API connections.</p>

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

            <button type="submit" class="button button-primary button-full">Sign in to Ask Asio</button>
        </form>
    </section>
</main>
</body>
</html>
