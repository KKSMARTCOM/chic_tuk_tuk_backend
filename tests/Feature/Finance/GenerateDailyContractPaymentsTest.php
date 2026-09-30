<?php

namespace Tests\Feature\Finance;

use App\Domains\Finance\Application\Actions\GenerateDailyContractPayments;
use App\Models\DriverContract;
use App\Models\Payment;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La génération du soir : un paiement par jour OUVRÉ et TRAVAILLÉ.
 *
 * Corrigé le 2026-09-30 : elle ignorait les pauses et créait un paiement en attente les
 * jours de pause d'agent et d'immobilisation — des paiements jamais dus.
 */
class GenerateDailyContractPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private VehicleContract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contract = VehicleContract::factory()->create(['start_date' => '2026-07-01']);
        DriverContract::factory()->forVehicleContract($this->contract)->create();
    }

    private function generateOn(string $date): int
    {
        app(GenerateDailyContractPayments::class)(Carbon::parse($date));

        return Payment::where('vehicle_contract_id', $this->contract->id)->whereDate('payment_date', $date)->count();
    }

    private function pause(string $start, ?string $end, string $reason): void
    {
        VehiclePause::factory()->forContract($this->contract)->create([
            'start_date' => $start, 'end_date' => $end, 'reason_type' => $reason,
        ]);
    }

    public function test_a_worked_business_day_gets_its_payment(): void
    {
        $this->assertSame(1, $this->generateOn('2026-07-15'));
    }

    public function test_no_payment_on_an_immobilization_day(): void
    {
        $this->pause('2026-07-14', '2026-07-16', 'technical');

        $this->assertSame(0, $this->generateOn('2026-07-15'));
    }

    public function test_no_payment_on_an_agent_pause_day(): void
    {
        $this->pause('2026-07-15', '2026-07-15', 'agent_leave');

        $this->assertSame(0, $this->generateOn('2026-07-15'));
    }

    public function test_no_payment_under_an_open_pause(): void
    {
        $this->pause('2026-07-10', null, 'agent_change');

        $this->assertSame(0, $this->generateOn('2026-07-15'));
    }

    public function test_a_pause_ended_the_day_before_does_not_block(): void
    {
        $this->pause('2026-07-13', '2026-07-14', 'technical');

        $this->assertSame(1, $this->generateOn('2026-07-15'));
    }

    public function test_still_no_payment_on_a_weekend(): void
    {
        $this->assertSame(0, $this->generateOn('2026-07-18'));
    }
}
