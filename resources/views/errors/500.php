<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
    <link rel="stylesheet" href="/assets/app.css">
</head>
<body>
<main class="error-card">
    <p class="eyebrow">Error</p>
    <h1><?= htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
    <p><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    <p class="request-id">Request ID: <?= htmlspecialchars($requestId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
</main>
</body>
</html>
