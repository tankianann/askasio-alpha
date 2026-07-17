<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\AuthenticationService;
use App\Auth\LoginRateLimiter;
use App\Auth\SessionStoreInterface;
use App\Domain\Admin\AdminUser;
use App\Http\Middleware\AdminAuthenticationMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\AdminRepositoryInterface;
use App\Security\CsrfTokenManager;
use App\Support\ViewRenderer;
use Psr\Log\LoggerInterface;

final class AuthController
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly LoginRateLimiter $rateLimiter,
        private readonly SessionStoreInterface $session,
        private readonly CsrfTokenManager $csrf,
        private readonly AdminRepositoryInterface $admins,
        private readonly ViewRenderer $views,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function showLogin(Request $request): Response
    {
        $adminId = $this->session->get(AdminAuthenticationMiddleware::SESSION_ADMIN_ID);

        if (is_int($adminId) && $this->admins->findById($adminId) instanceof AdminUser) {
            return Response::redirect('/admin');
        }

        return $this->loginView();
    }

    public function login(Request $request): Response
    {
        $username = $request->input('username');
        $password = $request->input('password');
        $username = is_string($username) ? AuthenticationService::normalizeUsername($username) : '';
        $password = is_string($password) ? $password : '';
        $ipAddress = $request->clientIp();

        if ($username === '' || $password === '' || strlen($username) > 64 || strlen($password) > 4096) {
            return $this->loginView('Username and password are required.', $username, 422);
        }

        if ($this->rateLimiter->tooManyAttempts($username, $ipAddress)) {
            $this->logger->warning('Administrator login was rate limited.', [
                'request_id' => $request->attribute('request_id'),
                'ip_hash' => $this->rateLimiter->ipIdentifier($ipAddress),
            ]);

            return $this->loginView(sprintf(
                'Too many login attempts. Please wait %d minutes and try again.',
                $this->rateLimiter->windowMinutes(),
            ), $username, 429);
        }

        $admin = $this->authentication->authenticate($username, $password);

        if (!$admin instanceof AdminUser) {
            $this->rateLimiter->recordFailure($username, $ipAddress);
            $this->logger->notice('Administrator login failed.', [
                'request_id' => $request->attribute('request_id'),
                'ip_hash' => $this->rateLimiter->ipIdentifier($ipAddress),
            ]);

            return $this->loginView('The supplied credentials are invalid.', $username, 422);
        }

        $this->rateLimiter->clear($username, $ipAddress);
        $this->session->regenerate();
        $this->csrf->rotate();
        $this->session->put(AdminAuthenticationMiddleware::SESSION_ADMIN_ID, $admin->id);
        $this->logger->info('Administrator logged in.', [
            'request_id' => $request->attribute('request_id'),
            'admin_user_id' => $admin->id,
        ]);

        return Response::redirect('/admin');
    }

    public function logout(Request $request): Response
    {
        $adminId = $this->session->get(AdminAuthenticationMiddleware::SESSION_ADMIN_ID);
        $this->session->invalidate();
        $this->logger->info('Administrator logged out.', [
            'request_id' => $request->attribute('request_id'),
            'admin_user_id' => is_int($adminId) ? $adminId : null,
        ]);

        return Response::redirect('/admin/login');
    }

    private function loginView(?string $error = null, string $username = '', int $status = 200): Response
    {
        return Response::html($this->views->render('auth/login', [
            'title' => 'Administrator login',
            'csrfToken' => $this->csrf->token(),
            'error' => $error,
            'username' => $username,
        ]), $status);
    }
}
