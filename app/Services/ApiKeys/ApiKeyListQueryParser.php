<?php

declare(strict_types=1);

namespace App\Services\ApiKeys;

use App\Domain\ApiKeys\ApiKeyListQuery;
use App\Domain\ApiKeys\ApiKeyListSort;
use App\Domain\ApiKeys\ApiKeyListStatus;
use App\Http\Request;
use App\Services\Admin\AdminListQueryInput;

final class ApiKeyListQueryParser
{
    public function __construct(private readonly string $timezone)
    {
    }

    public function parse(Request $request): ApiKeyListQuery
    {
        $input = new AdminListQueryInput($request, $this->timezone);
        /** @var ApiKeyListStatus $status */
        $status = $input->enum('status', ApiKeyListStatus::class, ApiKeyListStatus::All);
        /** @var ApiKeyListSort $sort */
        $sort = $input->enum('sort', ApiKeyListSort::class, ApiKeyListSort::Created);

        return new ApiKeyListQuery(
            $input->pagination(),
            $input->text('search', 190),
            $status,
            $sort,
            $input->direction(),
        );
    }
}
