<?php

namespace Tests\Feature\Finance;

use App\Domains\Finance\Application\Actions\GenerateDailyContractPayments;
use App\Models\DriverContract;
use App\Models\LeaveRequest;
use App\Models\Payment;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * Le dernier jour d'un contrat est dû quand l'agent n'était pas en pause (règle du
     * 2026-10-06). La génération ne lisait que les contrats ACTIFS : un contrat terminé
     * dans la journée perdait ce jour.
     */
    public function test_a_contract_ended_today_still_gets_its_last_day(): void
    {
        DriverContract::query()->update(['status' => 'ended', 'end_date' => '2026-07-15']);

        $this->assertSame(1, $this->generateOn('2026-07-15'));
    }

    public function test_a_contract_ended_before_gets_nothing(): void
    {
        DriverContract::query()->update(['status' => 'ended', 'end_date' => '2026-07-14']);

        $this->assertSame(0, $this->generateOn('2026-07-15'));
    }

    public function test_still_no_payment_on_a_weekend(): void
    {
        $this->assertSame(0, $this->generateOn('2026-07-18'));
    }

    public function test_payments_go_to_the_agents_own_vehicle_contract(): void
    {
        // Le contrat agent porte sur CE contrat véhicule, même si le véhicule en a un autre actif.
        $other = VehicleContract::factory()->create(['vehicle_id' => $this->contract->vehicle_id, 'start_date' => '2026-07-01']);

        $this->generateOn('2026-07-15');

        $this->assertSame(0, Payment::where('vehicle_contract_id', $other->id)->count());
    }

    public function test_no_payment_on_an_ongoing_agent_pause_without_a_vehicle_pause(): void
    {
        $driverContract = DriverContract::where('vehicle_contract_id', $this->contract->id)->first();
        LeaveRequest::factory()->ongoing()->create([
            'driver_id' => $driverContract->driver_id, 'driver_contract_id' => $driverContract->id, 'start_date' => '2026-07-14',
        ]);

        $this->assertSame(0, $this->generateOn('2026-07-15'));
    }

    public function test_the_agent_contract_is_locked_while_its_day_is_planned(): void
    {
        // Revue du 2026-10-01 : la génération sur une période verrouille le contrat agent ;
        // sans le même verrou ici, les deux pouvaient créer le paiement du même jour.
        $locked = $vehicleLocked = false;
        DB::listen(function ($query) use (&$locked, &$vehicleLocked) {
            if (str_contains($query->sql, 'driver_contracts') && str_contains(strtolower($query->sql), 'for update')) {
                $locked = true;
            }
            if (str_contains($query->sql, '"vehicle_contracts"') && str_contains(strtolower($query->sql), 'for update')) {
                $vehicleLocked = true;
            }
        });

        $this->assertSame(1, $this->generateOn('2026-07-15'));
        $this->assertTrue($locked);
        // Deux agents d'un même véhicule ne doivent pas générer le même jour en parallèle.
        $this->assertTrue($vehicleLocked);
    }

    public function test_the_command_refuses_a_future_date_out_loud(): void
    {
        // Le planificateur ne classe jamais un jour à venir : la commande le dit au lieu de
        // ne rien générer en silence (revue du 2026-10-01).
        $future = Carbon::tomorrow()->isWeekend() ? Carbon::today()->next(Carbon::MONDAY) : Carbon::tomorrow();

        $this->artisan('app:generate-daily', ['--date' => $future->toDateString()])
            ->expectsOutputToContain('date future')
            ->assertFailed();
        $this->assertSame(0, Payment::count());
    }
}
