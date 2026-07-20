<?php

declare(strict_types=1);

namespace App\Services\Chatbots;

use App\Domain\Chatbots\ChatbotListQuery;
use App\Domain\Chatbots\ChatbotListSort;
use App\Domain\Chatbots\ChatbotListStatus;
use App\Domain\Chatbots\ChatbotPublicationFilter;
use App\Http\Request;
use App\Services\Admin\AdminListQueryInput;

final readonly class ChatbotListQueryParser
{
    public function __construct(private string $timezone)
    {
    }

    public function parse(Request $request): ChatbotListQuery
    {
        $input = new AdminListQueryInput($request, $this->timezone);
        /** @var ChatbotListStatus $status */
        $status = $input->enum('status', ChatbotListStatus::class, ChatbotListStatus::All);
        /** @var ChatbotPublicationFilter $publication */
        $publication = $input->enum(
            'publication',
            ChatbotPublicationFilter::class,
            ChatbotPublicationFilter::All,
        );
        /** @var ChatbotListSort $sort */
        $sort = $input->enum('sort', ChatbotListSort::class, ChatbotListSort::Updated);

        return new ChatbotListQuery(
            $input->pagination(),
            $input->text('search', 190),
            $status,
            $publication,
            $sort,
            $input->direction(),
            $input->text('model', 190),
            $input->text('source', 190),
        );
    }
}
