<?php

use App\Libraries\ReceivableReminderPolicy;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../app/Libraries/ReceivableReminderPolicy.php';

class ReceivableReminderPolicyTest extends TestCase
{
    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->today = new DateTimeImmutable('2026-11-16');
    }

    public function testIdentifiesOverdueDueTodayAndExactH3Dates(): void
    {
        self::assertSame('overdue', ReceivableReminderPolicy::category('2026-11-15', 100.0, $this->today));
        self::assertSame('due_today', ReceivableReminderPolicy::category('2026-11-16', 100.0, $this->today));
        self::assertSame('h3', ReceivableReminderPolicy::category('2026-11-19', 100.0, $this->today));
        self::assertNull(ReceivableReminderPolicy::category('2026-11-18', 100.0, $this->today));
        self::assertNull(ReceivableReminderPolicy::category('2026-11-20', 100.0, $this->today));
    }

    public function testExcludesPaidReceivableAndKeepsPartialInstallmentBalance(): void
    {
        self::assertNull(ReceivableReminderPolicy::category('2026-11-19', 0.0, $this->today));
        self::assertSame('h3', ReceivableReminderPolicy::category('2026-11-19', 250.0, $this->today));
    }

    public function testExcludesReceivablesWithoutDueDateOrWithNegativeBalance(): void
    {
        self::assertNull(ReceivableReminderPolicy::category(null, 250.0, $this->today));
        self::assertNull(ReceivableReminderPolicy::category('2026-11-19', -1.0, $this->today));
    }
}
