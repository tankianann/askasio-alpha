<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Domain\Admin\AdminUser;
use App\Http\Request;
use App\Http\Response;
use App\Security\CsrfTokenManager;
use App\Services\Ingestion\IngestionQueue;
use App\Support\ViewRenderer;

final class JobController
{
    public function __construct(
        private readonly IngestionQueue $queue,
        private readonly ViewRenderer $views,
        private readonly CsrfTokenManager $csrf,
        private readonly string $environment,
        private readonly bool $pipelineAvailable,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $admin = $request->attribute('admin_user');

        if (!$admin instanceof AdminUser) {
            throw new \LogicException('Authenticated administrator is missing from the request.');
        }

        return Response::html($this->views->render('jobs/index', [
            'title' => 'Ingestion jobs',
            'admin' => $admin,
            'csrfToken' => $this->csrf->token(),
            'environment' => $this->environment,
            'currentSection' => 'jobs',
            'jobs' => $this->queue->recent(100),
            'counts' => $this->queue->counts(),
            'pipelineAvailable' => $this->pipelineAvailable,
        ], 'layouts/admin'));
    }
}
