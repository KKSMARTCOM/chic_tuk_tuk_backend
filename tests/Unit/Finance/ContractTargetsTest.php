<?php

namespace Tests\Unit\Finance;

use App\Domains\Finance\Domain\ContractTargets;
use App\Models\VehicleContract;
use PHPUnit\Framework\TestCase;

class ContractTargetsTest extends TestCase
{
    private function contract(): VehicleContract
    {
        return new VehicleContract([
            'total_amount' => 3_100_000, 'contract_months' => 24,
            'unlimited_internet' => 5_000, 'spotify_premium' => 2_500, 'manager_remuneration' => 20_000,
        ]);
    }

    public function test_the_two_targets(): void
    {
        $this->assertEquals(3_100_000, ContractTargets::revenue($this->contract()));
        $this->assertEquals(660_000, ContractTargets::charges($this->contract()));
        $this->assertEquals(['internet' => 120_000, 'spotify' => 60_000, 'manager' => 480_000], ContractTargets::chargeLines($this->contract()));
    }

    public function test_null_charges_count_as_zero(): void
    {
        $contract = new VehicleContract(['total_amount' => 100, 'contract_months' => 24]);

        $this->assertEquals(0, ContractTargets::charges($contract));
    }

    public function test_reached_within_half_a_franc(): void
    {
        $this->assertTrue(ContractTargets::isReached(659_999.996, 660_000));
        $this->assertFalse(ContractTargets::isReached(659_999.99, 660_000));
        $this->assertTrue(ContractTargets::isReached(0, 0));
    }
}
