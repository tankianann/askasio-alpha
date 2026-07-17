<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Throwable;

final class SecretRedactionProcessor
{
    private const REDACTED = '[REDACTED]';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactString($record->message),
            context: $this->redactArray($record->context),
            extra: $this->redactArray($record->extra),
        );
    }

    /** @param array<mixed> $values
     *  @return array<mixed>
     */
    private function redactArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match('/password|secret|token|authorization|api[_-]?key/i', $key) === 1) {
                $values[$key] = self::REDACTED;
                continue;
            }

            $values[$key] = $this->redactValue($value);
        }

        return $values;
    }

    private function redactValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->redactArray($value);
        }

        if ($value instanceof Throwable) {
            return [
                'class' => $value::class,
                'code' => $value->getCode(),
                'message' => $this->redactString($value->getMessage()),
            ];
        }

        return is_string($value) ? $this->redactString($value) : $value;
    }

    private function redactString(string $value): string
    {
        $patterns = [
            '/(Bearer\s+)[^\s]+/i' => '$1' . self::REDACTED,
            '/\b(?:sk-[A-Za-z0-9_-]{8,}|rag_(?:live|test)_[A-Za-z0-9_-]{8,})\b/' => self::REDACTED,
            '/((?:api[_-]?key|password|secret|token)\s*[=:]\s*)[^\s,;]+/i' => '$1' . self::REDACTED,
            '/([a-z][a-z0-9+.-]*:\/\/[^:\s\/]+:)[^@\s\/]+@/i' => '$1' . self::REDACTED . '@',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $value);
    }
}
