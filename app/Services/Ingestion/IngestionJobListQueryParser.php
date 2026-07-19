<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Domain\Ingestion\IngestionJobListQuery;
use App\Domain\Ingestion\IngestionJobListSort;
use App\Domain\Ingestion\JobStatus;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Services\Admin\AdminListQueryInput;

final class IngestionJobListQueryParser
{
    public function __construct(private readonly string $timezone)
    {
    }

    public function parse(Request $request): IngestionJobListQuery
    {
        $input = new AdminListQueryInput($request, $this->timezone);
        [$dateFrom, $dateTo, $createdFromUtc, $createdBeforeUtc] = $input->dateRange();
        $minimumAttempts = $input->integer('attempts_min', null, 0, 100);
        $maximumAttempts = $input->integer('attempts_max', null, 0, 100);

        if ($minimumAttempts !== null && $maximumAttempts !== null && $minimumAttempts > $maximumAttempts) {
            throw new ValidationException('The attempts_min parameter must not exceed attempts_max.');
        }

        /** @var IngestionJobListSort $sort */
        $sort = $input->enum('sort', IngestionJobListSort::class, IngestionJobListSort::Date);

        return new IngestionJobListQuery(
            $input->pagination(),
            $this->optionalStatus($input),
            $input->integer('source_id', null, 1, PHP_INT_MAX),
            $dateFrom,
            $dateTo,
            $createdFromUtc,
            $createdBeforeUtc,
            $minimumAttempts,
            $maximumAttempts,
            $sort,
            $input->direction(),
        );
    }

    private function optionalStatus(AdminListQueryInput $input): ?JobStatus
    {
        if ($input->text('status', 20) === null) {
            return null;
        }

        /** @var JobStatus */
        return $input->enum('status', JobStatus::class, JobStatus::Pending);
    }
}
