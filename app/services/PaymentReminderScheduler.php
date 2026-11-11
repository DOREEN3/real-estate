<?php

declare(strict_types=1);

require_once __DIR__ . '/../models/PaymentReminderModel.php';
require_once __DIR__ . '/../models/RentalModel.php';
require_once __DIR__ . '/../models/CompanyProfileModel.php';
require_once __DIR__ . '/NotificationDispatcher.php';
require_once __DIR__ . '/PaymentReminderSchedule.php';

class PaymentReminderScheduler
{
    private PaymentReminderModel $reminderModel;
    private RentalModel $rentalModel;
    private NotificationDispatcher $dispatcher;

    public function __construct()
    {
        $this->reminderModel = new PaymentReminderModel();
        $this->rentalModel = new RentalModel();
        $this->dispatcher = new NotificationDispatcher();
    }

    public function run(?DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $this->generateUpcomingRentCharges($today);

        $through = $today->modify('+7 days')->format('Y-m-d');
        $targets = array_merge(
            $this->reminderModel->installmentBalances($through),
            $this->reminderModel->rentChargeBalances($through)
        );
        $profile = (new CompanyProfileModel())->get();
        $counts = ['processed' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($targets as $target) {
            $event = PaymentReminderSchedule::eventForDueDate(
                (string) $target['due_date'],
                $today,
                (int) ($target['grace_period_days'] ?? 0)
            );
            if ((float) $target['outstanding_balance'] <= 0) {
                continue;
            }
            $events = array_values(array_unique(array_merge(
                $event === null ? [] : [$event],
                $this->reminderModel->retryEventsForTarget($target)
            )));
            if ($events === []) {
                continue;
            }
            $invoice = $this->reminderModel->getOrCreateInvoice($target, $today->format('Y-m-d'));
            foreach ($events as $eventToSend) {
                $counts['processed']++;
                $dispatch = $this->reminderModel->ensureDispatch(
                    $target,
                    $eventToSend,
                    $today->format('Y-m-d')
                );
                $dispatchId = (int) $dispatch['id'];
                $amount = number_format((float) $target['outstanding_balance'], 2, '.', '');

                foreach (['email', 'sms'] as $channel) {
                    if (!$this->reminderModel->claimChannel($dispatchId, $channel)) {
                        $counts['skipped']++;
                        continue;
                    }

                    $contact = trim((string) ($target[$channel === 'email' ? 'recipient_email' : 'recipient_phone'] ?? ''));
                    if ($contact === '') {
                        $this->reminderModel->finishChannel($dispatchId, $channel, 'skipped');
                        $counts['skipped']++;
                        continue;
                    }

                    try {
                        $result = $this->dispatcher->dispatchDuePaymentNotice(
                            $channel,
                            $target,
                            $invoice,
                            $eventToSend,
                            $amount,
                            $profile
                        );
                        $status = !empty($result['success']) ? 'sent' : 'failed';
                        $this->reminderModel->finishChannel(
                            $dispatchId,
                            $channel,
                            $status,
                            $status === 'failed' ? (string) ($result['message'] ?? 'Delivery failed') : null
                        );
                        $counts[$status]++;
                    } catch (Throwable $exception) {
                        $this->reminderModel->finishChannel(
                            $dispatchId,
                            $channel,
                            'failed',
                            $exception->getMessage()
                        );
                        error_log('Payment reminder delivery failed: ' . $exception->getMessage());
                        $counts['failed']++;
                    }
                }
            }
        }

        return $counts;
    }

    private function generateUpcomingRentCharges(DateTimeImmutable $today): void
    {
        $throughMonth = $today->modify('first day of next month')->format('Y-m');
        $fromMonth = $today->modify('first day of this month')->format('Y-m');
        foreach ($this->reminderModel->activeTenancyIds() as $tenancyId) {
            try {
                $this->rentalModel->generateRentCharges($tenancyId, $throughMonth, $fromMonth);
            } catch (DomainException $exception) {
                if ($exception->getMessage() !== 'invalid_charge_range') {
                    throw $exception;
                }
            }
        }
    }
}
