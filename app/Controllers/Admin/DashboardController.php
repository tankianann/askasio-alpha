<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Domain\Admin\AdminUser;
use App\Http\Request;
use App\Http\Response;
use App\Security\CsrfTokenManager;
use App\Support\ViewRenderer;

final class DashboardController
{
    public function __construct(
        private readonly ViewRenderer $views,
        private readonly CsrfTokenManager $csrf,
        private readonly string $environment,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $admin = $request->attribute('admin_user');

        if (!$admin instanceof AdminUser) {
            throw new \LogicException('Authenticated administrator is missing from the request.');
        }

        return Response::html($this->views->render('admin/dashboard', [
            'title' => 'Dashboard',
            'admin' => $admin,
            'csrfToken' => $this->csrf->token(),
            'environment' => $this->environment,
        ], 'layouts/admin'));
    }
}
