<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/services/PaymentReminderSchedule.php';

$today = new DateTimeImmutable('2026-11-10');
$cases = [
    ['2026-11-17', 0, 'reminder_7_days'],
    ['2026-11-13', 0, 'reminder_3_days'],
    ['2026-11-10', 0, 'due_today'],
    ['2026-11-17', 3, 'reminder_7_days'],
    ['2026-11-10', 3, 'due_today'],
    ['2026-11-06', 3, 'overdue'],
    ['2026-10-30', 3, 'overdue_followup'],
    ['2026-11-15', 0, null],
];

foreach ($cases as [$dueDate, $graceDays, $expected]) {
    $actual = PaymentReminderSchedule::eventForDueDate(
        $dueDate,
        $today,
        $graceDays
    );
    if ($actual !== $expected) {
        throw new RuntimeException(
            "Expected {$expected} for due date {$dueDate} with {$graceDays} grace days; got " . ($actual ?? 'null')
        );
    }
}

foreach ([
    ['not-a-date', 0],
    ['2026-11-20', -1],
    ['2026-11-20', 31],
] as [$dueDate, $graceDays]) {
    try {
        PaymentReminderSchedule::eventForDueDate($dueDate, $today, $graceDays);
        throw new RuntimeException('Invalid schedule input was accepted.');
    } catch (InvalidArgumentException) {
    }
}

fwrite(STDOUT, "Payment reminder schedule checks passed.\n");
