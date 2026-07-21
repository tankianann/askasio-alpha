<?php

declare(strict_types=1);

namespace App\Services\Chatbots;

use App\Domain\Chatbots\ChatbotConversationListQuery;
use App\Domain\Chatbots\ChatbotSessionStatus;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Services\Admin\AdminListQueryInput;

final readonly class ChatbotConversationListQueryParser
{
    public function __construct(private string $timezone)
    {
    }

    public function parse(Request $request): ChatbotConversationListQuery
    {
        $input = new AdminListQueryInput($request, $this->timezone);
        $traffic = $request->query('traffic');
        $traffic = is_string($traffic) ? trim($traffic) : null;

        if (!in_array($traffic, [null, '', 'production', 'test'], true)) {
            throw new ValidationException('The traffic parameter is invalid.');
        }

        $statusValue = $request->query('status');
        $status = null;
        if (is_string($statusValue) && $statusValue !== '') {
            try {
                $status = ChatbotSessionStatus::from($statusValue);
            } catch (\ValueError) {
                throw new ValidationException('The status parameter is invalid.');
            }
        }

        $sort = $request->query('sort');
        $sort = is_string($sort) && $sort !== '' ? $sort : 'activity';
        if (!in_array($sort, ['activity', 'started', 'messages', 'usage'], true)) {
            throw new ValidationException('The sort parameter is invalid.');
        }

        [$dateFrom, $dateTo, $fromUtc, $beforeUtc] = $input->dateRange();

        return new ChatbotConversationListQuery(
            $input->pagination(),
            $input->text('search', 64),
            $input->integer('chatbot_id', null, 1, PHP_INT_MAX),
            $status,
            $traffic === null || $traffic === '' ? null : $traffic === 'test',
            $dateFrom,
            $dateTo,
            $fromUtc,
            $beforeUtc,
            $sort,
            $input->direction(),
        );
    }
}
