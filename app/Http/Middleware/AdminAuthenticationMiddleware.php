<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\SessionStoreInterface;
use App\Domain\Admin\AdminUser;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\AdminRepositoryInterface;
use Closure;

final class AdminAuthenticationMiddleware implements MiddlewareInterface
{
    public const SESSION_ADMIN_ID = 'admin_user_id';

    public function __construct(
        private readonly SessionStoreInterface $session,
        private readonly AdminRepositoryInterface $admins,
    ) {
    }

    public function process(Request $request, Closure $next): Response
    {
        $adminId = $this->session->get(self::SESSION_ADMIN_ID);
        $admin = is_int($adminId) ? $this->admins->findById($adminId) : null;

        if (!$admin instanceof AdminUser) {
            $this->session->remove(self::SESSION_ADMIN_ID);

            return Response::redirect('/admin/login');
        }

        return $next($request->withAttribute('admin_user', $admin));
    }
}
