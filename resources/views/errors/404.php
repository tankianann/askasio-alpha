<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · Ask Asio</title>
    <link rel="icon" href="/assets/images/ask-asio.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/app.css?v=18">
</head>
<body class="error-body">
<main class="error-card">
    <a class="auth-brand" href="/admin" aria-label="Ask Asio overview">
        <img src="/assets/images/ask-asio.svg" alt="">
        <span>Ask Asio</span>
    </a>
    <p class="eyebrow">404</p>
    <h1><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
    <p><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <p class="request-id">Request ID: <?= htmlspecialchars($requestId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
</main>
</body>
</html>
