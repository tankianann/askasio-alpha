<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#ffffff">
    <title><?= $escape($title) ?> · Ask Archie</title>
    <link rel="icon" href="/assets/images/ask-archie.svg" type="image/svg+xml">
    <link rel="stylesheet" href="/assets/app.css?v=10">
    <script src="/assets/app.js?v=10" defer></script>
</head>
<body class="admin-body">
<div class="admin-shell">
    <header class="mobile-topbar">
        <a class="brand" href="/admin" aria-label="Ask Archie overview">
            <img class="brand-logo" src="/assets/images/ask-archie.svg" alt="">
            <span class="brand-name">Ask Archie</span>
        </a>
        <button class="mobile-menu-button" type="button" aria-controls="admin-sidebar" aria-expanded="false" aria-label="Open navigation" data-sidebar-open>
            <svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-menu"></use></svg>
        </button>
    </header>

    <aside class="sidebar" id="admin-sidebar" aria-label="Primary navigation">
        <div class="sidebar-main">
            <a class="brand" href="/admin" aria-label="Ask Archie overview">
                <img class="brand-logo" src="/assets/images/ask-archie.svg" alt="">
                <span class="brand-name">Ask Archie</span>
            </a>
            <button class="sidebar-close" type="button" aria-label="Close navigation" data-sidebar-close>
                <svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-close"></use></svg>
            </button>

            <p class="nav-label">Workspace</p>
            <nav>
                <a href="/admin" <?= $currentSection === 'dashboard' ? 'aria-current="page"' : '' ?>>
                    <svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-overview"></use></svg>
                    <span>Overview</span>
                </a>
                <a href="/admin/sources" <?= $currentSection === 'sources' ? 'aria-current="page"' : '' ?>>
                    <svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-knowledge"></use></svg>
                    <span>Knowledge Base</span>
                </a>
                <a href="/admin/jobs" <?= $currentSection === 'jobs' ? 'aria-current="page"' : '' ?>>
                    <svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-processing"></use></svg>
                    <span>Processing</span>
                </a>
                <a href="/admin/api-keys" <?= $currentSection === 'api_keys' ? 'aria-current="page"' : '' ?>>
                    <svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-access"></use></svg>
                    <span>API Access</span>
                </a>
                <a href="/admin/api-requests" <?= $currentSection === 'api_requests' ? 'aria-current="page"' : '' ?>>
                    <svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-activity"></use></svg>
                    <span>API Activity</span>
                </a>
            </nav>
        </div>

        <div class="sidebar-footer">
            <div class="account-summary">
                <span class="account-avatar" aria-hidden="true"><?= $escape(strtoupper(mb_substr($admin->username, 0, 1))) ?></span>
                <div class="account-copy">
                    <span class="account-name"><?= $escape($admin->username) ?></span>
                    <span class="environment-label"><?= $escape($environment) ?> environment</span>
                </div>
            </div>
            <form class="logout-form" method="post" action="/admin/logout">
                <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                <button type="submit" class="button button-sidebar">
                    <svg class="icon" aria-hidden="true"><use href="/assets/icons.svg#icon-logout"></use></svg>
                    <span>Log out</span>
                </button>
            </form>
        </div>
    </aside>

    <button class="sidebar-scrim" type="button" tabindex="-1" aria-hidden="true" data-sidebar-close></button>

    <main class="admin-main" id="main-content">
        <div class="admin-content">
            <?= $content ?>
        </div>
    </main>
</div>
</body>
</html>
