<?php

namespace Tests\Unit\Finance;

use App\Domains\Finance\Domain\ChargeLedger;
use PHPUnit\Framework\TestCase;

class ChargeLedgerTest extends TestCase
{
    private const DUE = ['internet' => 5_000.0, 'spotify' => 2_500.0, 'manager' => 20_000.0];

    public function test_the_proposal_takes_everything_due_when_the_month_covers_it(): void
    {
        $this->assertSame(self::DUE, ChargeLedger::propose(self::DUE, 58_710));
    }

    public function test_the_proposal_serves_lines_in_sheet_order_within_what_the_month_brings(): void
    {
        // 21 848 disponibles : internet et Spotify entiers, le manager pour le reste.
        $this->assertSame(
            ['internet' => 5_000.0, 'spotify' => 2_500.0, 'manager' => 14_348.0],
            ChargeLedger::propose(self::DUE, 21_848),
        );
    }

    public function test_the_proposal_is_zero_when_nothing_comes_in(): void
    {
        $this->assertSame(['internet' => 0.0, 'spotify' => 0.0, 'manager' => 0.0], ChargeLedger::propose(self::DUE, 0));
    }

    public function test_deducting_more_than_outstanding_is_refused_line_by_line(): void
    {
        $violations = ChargeLedger::violations(['internet' => 6_000, 'spotify' => 2_500, 'manager' => 0], self::DUE, 100_000);

        $this->assertArrayHasKey('deducted_internet', $violations);
        $this->assertArrayNotHasKey('deducted_spotify', $violations);
    }

    public function test_a_negative_balance_is_refused(): void
    {
        $this->assertArrayHasKey('deducted', ChargeLedger::violations(self::DUE, self::DUE, 20_000));
    }

    public function test_deducting_nothing_is_allowed(): void
    {
        $this->assertSame([], ChargeLedger::violations(['internet' => 0, 'spotify' => 0, 'manager' => 0], self::DUE, 21_848));
    }

    public function test_a_negative_deduction_is_refused(): void
    {
        $this->assertArrayHasKey('deducted_manager', ChargeLedger::violations(['internet' => 0, 'spotify' => 0, 'manager' => -1], self::DUE, 100));
    }
}
