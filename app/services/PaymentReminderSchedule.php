<?php

declare(strict_types=1);

class PaymentReminderSchedule
{
    public static function eventForDueDate(
        string $dueDate,
        DateTimeImmutable $today,
        int $gracePeriodDays = 0
    ): ?string {
        $due = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $due === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $due->format('Y-m-d') !== $dueDate
        ) {
            throw new InvalidArgumentException('Payment due date is invalid.');
        }
        if ($gracePeriodDays < 0 || $gracePeriodDays > 30) {
            throw new InvalidArgumentException('Grace period must be between 0 and 30 days.');
        }

        $daysUntilDue = (int) $today->diff($due)->format('%r%a');
        return match (true) {
            $daysUntilDue === 7 => 'reminder_7_days',
            $daysUntilDue === 3 => 'reminder_3_days',
            $daysUntilDue === 0 => 'due_today',
            $daysUntilDue === -($gracePeriodDays + 1) => 'overdue',
            $daysUntilDue === -($gracePeriodDays + 8) => 'overdue_followup',
            default => null,
        };
    }
}
