<?php

namespace Tests\Feature\Finance;

use App\Models\DriverContract;
use App\Models\Payment;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** L'audit des paiements de contrat avant reconstitution, en lecture seule (spec 2026-10-01, §9.1). */
class AuditContractPaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_reports_the_first_month_and_each_kind_of_problem_without_writing(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00');
        VehicleContract::factory()->create(['start_date' => '2025-06-16']);
        $contract = VehicleContract::factory()->create(['start_date' => '2026-03-01']);
        $agent = DriverContract::factory()->forVehicleContract($contract)->create(['start_date' => '2026-03-02']);
        $base = ['vehicle_contract_id' => $contract->id, 'driver_contract_id' => $agent->id, 'driver_id' => $agent->driver_id];

        Payment::factory()->create($base + ['payment_month' => null, 'payment_date' => '2026-05-12']);
        Payment::factory()->onDay('2026-03-02')->create(['vehicle_contract_id' => null, 'driver_contract_id' => null]);
        VehiclePause::factory()->forContract($contract)->create(['start_date' => '2026-03-09', 'end_date' => '2026-03-09', 'reason_type' => 'technical']);
        Payment::factory()->onDay('2026-03-09')->create($base);
        Payment::factory()->onDay('2026-03-10')->create($base);
        Payment::factory()->onDay('2026-03-10')->status('pending')->create($base);
        $before = Payment::query()->orderBy('id')->get()->toJson();

        $this->artisan('app:audit-contract-payments')
            ->expectsOutputToContain('Plus ancien contrat véhicule : juin 2025 (REMUNERATION_FIRST_MONTH=2025-06 ou plus tôt)')
            ->expectsOutputToContain('Sans mois : 1')
            ->expectsOutputToContain('Sans contrat : 1')
            ->expectsOutputToContain('Sur un jour d\'arrêt : 1')
            ->expectsOutputToContain('Doublons du même jour : 1')
            ->expectsOutputToContain('En attente d\'un mois passé : 1')
            ->assertSuccessful();

        $this->assertSame($before, Payment::query()->orderBy('id')->get()->toJson());
    }

    public function test_without_contracts_it_says_so(): void
    {
        $this->artisan('app:audit-contract-payments')->expectsOutputToContain('Aucun contrat véhicule.')->assertSuccessful();
    }

    public function test_two_agents_paid_the_same_day_on_one_vehicle_is_a_duplicate(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00');
        $contract = VehicleContract::factory()->create(['start_date' => '2026-03-01']);
        $a = DriverContract::factory()->forVehicleContract($contract)->create(['start_date' => '2026-03-02', 'end_date' => '2026-03-13']);
        $b = DriverContract::factory()->forVehicleContract($contract)->create(['start_date' => '2026-03-13']);
        Payment::factory()->onDay('2026-03-13')->create(['vehicle_contract_id' => $contract->id, 'driver_contract_id' => $a->id, 'driver_id' => $a->driver_id]);
        Payment::factory()->onDay('2026-03-13')->create(['vehicle_contract_id' => $contract->id, 'driver_contract_id' => $b->id, 'driver_id' => $b->driver_id]);

        $this->artisan('app:audit-contract-payments')->expectsOutputToContain('Doublons du même jour : 1')->assertSuccessful();
    }
}
