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

    public function test_lines_may_be_split_freely_within_the_total_outstanding(): void
    {
        // 15 000 de connexion pour 5 000 au contrat : le total, 27 500, n'est pas dépassé.
        $this->assertSame([], ChargeLedger::violations(['internet' => 15_000, 'spotify' => 2_500, 'manager' => 10_000], self::DUE, 100_000));
    }

    public function test_deducting_more_than_the_total_outstanding_is_refused(): void
    {
        $violations = ChargeLedger::violations(['internet' => 15_000, 'spotify' => 2_500, 'manager' => 10_001], self::DUE, 100_000);

        $this->assertArrayHasKey('deducted', $violations);
        $this->assertStringContainsString('27 500', $violations['deducted']);
    }

    public function test_a_negative_outstanding_proposes_nothing_and_lowers_the_total(): void
    {
        // Un mois a prélevé 10 000 de connexion de trop : il reste 12 500 au total.
        $this->assertSame(
            ['internet' => 0.0, 'spotify' => 2_500.0, 'manager' => 10_000.0],
            ChargeLedger::propose(['internet' => -10_000.0] + self::DUE, 100_000),
        );
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
