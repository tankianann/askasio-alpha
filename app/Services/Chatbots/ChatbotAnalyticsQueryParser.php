<?php

declare(strict_types=1);

namespace App\Services\Chatbots;

use App\Domain\Chatbots\ChatbotAnalyticsQuery;
use App\Exceptions\ValidationException;
use App\Http\Request;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final readonly class ChatbotAnalyticsQueryParser
{
    public function __construct(private string $timezone)
    {
    }

    public function parse(Request $request): ChatbotAnalyticsQuery
    {
        $zone = new DateTimeZone($this->timezone);
        $today = new DateTimeImmutable('today', $zone);
        $fromValue = $this->string($request, 'date_from') ?? $today->sub(new DateInterval('P29D'))->format('Y-m-d');
        $toValue = $this->string($request, 'date_to') ?? $today->format('Y-m-d');
        $from = $this->date($fromValue, $zone, 'date_from');
        $to = $this->date($toValue, $zone, 'date_to');
        $days = $from->diff($to)->days;

        if ($from > $to || !is_int($days) || $days > 89) {
            throw new ValidationException('Analytics date range must contain between 1 and 90 days.');
        }

        $chatbotValue = $this->string($request, 'chatbot_id');
        $chatbotId = $chatbotValue === null ? null : filter_var($chatbotValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($chatbotValue !== null && !is_int($chatbotId)) {
            throw new ValidationException('The chatbot_id parameter is invalid.');
        }

        $traffic = $this->string($request, 'traffic') ?? 'production';

        if (!in_array($traffic, ['production', 'test', 'all'], true)) {
            throw new ValidationException('The traffic parameter is invalid.');
        }

        $utc = new DateTimeZone('UTC');

        return new ChatbotAnalyticsQuery(
            $fromValue,
            $toValue,
            $from->setTimezone($utc)->format('Y-m-d H:i:s'),
            $to->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
            $chatbotId,
            $traffic === 'all' ? null : $traffic === 'test',
        );
    }

    private function string(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new ValidationException('The ' . $key . ' parameter must be a string.');
        }

        return trim($value);
    }

    private function date(string $value, DateTimeZone $zone, string $field): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
        $errors = DateTimeImmutable::getLastErrors();

        if (!$date instanceof DateTimeImmutable
            || $date->format('Y-m-d') !== $value
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new ValidationException('The ' . $field . ' parameter must use YYYY-MM-DD.');
        }

        return $date;
    }
}
