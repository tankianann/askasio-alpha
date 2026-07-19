<?php

declare(strict_types=1);

namespace App\Services\Sources;

use App\Domain\Sources\ProcessingStatus;
use App\Domain\Sources\SourceListAvailability;
use App\Domain\Sources\SourceListQuery;
use App\Domain\Sources\SourceListSort;
use App\Domain\Sources\SourceType;
use App\Http\Request;
use App\Services\Admin\AdminListQueryInput;

final class SourceListQueryParser
{
    public function __construct(private readonly string $timezone)
    {
    }

    public function parse(Request $request): SourceListQuery
    {
        $input = new AdminListQueryInput($request, $this->timezone);

        /** @var SourceListAvailability $availability */
        $availability = $input->enum('availability', SourceListAvailability::class, SourceListAvailability::All);
        /** @var SourceListSort $sort */
        $sort = $input->enum('sort', SourceListSort::class, SourceListSort::Updated);

        return new SourceListQuery(
            $input->pagination(),
            $input->text('search', 190),
            $this->optionalEnum($input, 'type', SourceType::class),
            $availability,
            $this->optionalEnum($input, 'processing', ProcessingStatus::class),
            $sort,
            $input->direction(),
        );
    }

    /** @template T of \BackedEnum
     *  @param class-string<T> $enum
     *  @return T|null
     */
    private function optionalEnum(AdminListQueryInput $input, string $parameter, string $enum): ?\BackedEnum
    {
        $sentinel = $input->text($parameter, 32);

        if ($sentinel === null) {
            return null;
        }

        /** @var \BackedEnum */
        return $input->enum($parameter, $enum, $enum::cases()[0]);
    }
}
