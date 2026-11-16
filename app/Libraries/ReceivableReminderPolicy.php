<?php

namespace App\Libraries;

use DateTimeImmutable;
use DateTimeZone;

class ReceivableReminderPolicy
{
    public const DAYS_BEFORE_DUE = 3;

    public static function todayInJakarta(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $timezone = new DateTimeZone('Asia/Jakarta');

        return ($now ?? new DateTimeImmutable('now', $timezone))
            ->setTimezone($timezone)
            ->setTime(0, 0);
    }

    public static function category(?string $dueDate, float $outstandingAmount, DateTimeImmutable $today): ?string
    {
        if ($dueDate === null || $outstandingAmount <= 0) {
            return null;
        }

        $todayString = $today->format('Y-m-d');
        if ($dueDate < $todayString) {
            return 'overdue';
        }
        if ($dueDate === $todayString) {
            return 'due_today';
        }
        if ($dueDate === $today->modify('+' . self::DAYS_BEFORE_DUE . ' days')->format('Y-m-d')) {
            return 'h3';
        }

        return null;
    }
}
