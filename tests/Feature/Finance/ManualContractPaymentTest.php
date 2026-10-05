<?php

namespace Tests\Feature\Finance;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Plus aucun paiement de contrat invisible des fiches (spec 2026-10-01, §6). */
class ManualContractPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 09:00:00');
        config(['remuneration.first_month' => '2026-03']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function api(): self
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'create-payments', 'guard_name' => 'web']));
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function body(Driver $driver, array $extra = []): array
    {
        return array_merge(['driver_id' => $driver->id, 'payment_type' => 'contract', 'amount' => 6112, 'payment_method' => 'cash', 'payment_date' => '2026-05-12'], $extra);
    }

    public function test_a_resigned_agents_payment_goes_to_the_chosen_ended_contract_with_its_month(): void
    {
        $vehicleContract = VehicleContract::factory()->create(['start_date' => '2026-03-01']);
        $ended = DriverContract::factory()->forVehicleContract($vehicleContract)->create(['start_date' => '2026-03-02', 'end_date' => '2026-04-15', 'status' => 'ended']);

        $this->api()->postJson('/api/v1/admin/payments', $this->body($ended->driver, ['driver_contract_id' => $ended->id]))->assertCreated();

        $payment = Payment::sole();
        $this->assertSame([$ended->id, $vehicleContract->id, '2026-05-01', '2026-05-12'],
            [$payment->driver_contract_id, $payment->vehicle_contract_id, $payment->payment_month->toDateString(), $payment->collected_on->toDateString()]);
    }

    public function test_someone_elses_contract_is_refused(): void
    {
        $mine = DriverContract::factory()->create();
        $other = DriverContract::factory()->create();

        $this->api()->postJson('/api/v1/admin/payments', $this->body($mine->driver, ['driver_contract_id' => $other->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['driver_contract_id' => 'Ce contrat n\'est pas celui de l\'agent.']);
    }

    public function test_without_a_contract_id_the_active_contract_is_kept_and_the_month_is_set(): void
    {
        $active = DriverContract::factory()->create(['status' => 'active']);

        $this->api()->postJson('/api/v1/admin/payments', $this->body($active->driver))->assertCreated();

        $this->assertSame([$active->id, '2026-05-01'], [Payment::sole()->driver_contract_id, Payment::sole()->payment_month->toDateString()]);
    }

    public function test_payable_drivers_for_contracts_include_resigned_agents_with_their_contracts(): void
    {
        $ended = DriverContract::factory()->create(['status' => 'ended', 'end_date' => '2026-04-15']);
        DriverContract::factory()->create(['status' => 'active']);

        $all = $this->api()->getJson('/api/v1/admin/payments/payable-drivers?type=contract')->assertOk()->json();
        $active = $this->api()->getJson('/api/v1/admin/payments/payable-drivers')->assertOk()->json();

        $this->assertCount(2, $all);
        $this->assertCount(1, $active);
        $this->assertSame($ended->id, collect($all)->firstWhere('id', $ended->driver_id)['contracts'][0]['id']);
    }

    public function test_a_manual_payment_an_issued_statement_should_have_counted_is_refused(): void
    {
        $vehicleContract = VehicleContract::factory()->create(['start_date' => '2026-03-01']);
        $agent = DriverContract::factory()->forVehicleContract($vehicleContract)->create(['start_date' => '2026-03-02', 'status' => 'active']);
        RemunerationStatement::factory()->for($vehicleContract, 'contract')->validated()->create(['month' => '2026-03-01', 'issued_on' => '2026-04-03']);

        $this->api()->postJson('/api/v1/admin/payments', $this->body($agent->driver, ['driver_contract_id' => $agent->id, 'payment_date' => '2026-03-15']))
            ->assertStatus(422)->assertJsonValidationErrors('payment_date');
        $this->api()->postJson('/api/v1/admin/payments', $this->body($agent->driver, ['driver_contract_id' => $agent->id, 'payment_date' => '2026-10-02']))
            ->assertStatus(422)->assertJsonValidationErrors('payment_date');
        $this->assertSame(0, Payment::count());

        $this->api()->postJson('/api/v1/admin/payments', $this->body($agent->driver, ['driver_contract_id' => $agent->id, 'payment_date' => '2026-04-10']))
            ->assertCreated();
    }

    public function test_a_late_payment_after_the_agents_contract_but_inside_the_vehicle_contract_is_accepted(): void
    {
        // Le cas C : l'agent parti règle son arriéré pendant que le contrat véhicule continue.
        $vehicleContract = VehicleContract::factory()->create(['start_date' => '2026-03-01']);
        $ended = DriverContract::factory()->forVehicleContract($vehicleContract)->create(['start_date' => '2026-03-02', 'end_date' => '2026-04-15', 'status' => 'ended']);

        $this->api()->postJson('/api/v1/admin/payments', $this->body($ended->driver, ['driver_contract_id' => $ended->id, 'payment_date' => '2026-05-12']))
            ->assertCreated();
    }

    public function test_a_date_outside_the_vehicle_contract_is_refused(): void
    {
        $vehicleContract = VehicleContract::factory()->create(['start_date' => '2026-03-01', 'end_date' => '2026-06-30', 'status' => 'completed']);
        $ended = DriverContract::factory()->forVehicleContract($vehicleContract)->create(['start_date' => '2026-03-02', 'end_date' => '2026-06-30', 'status' => 'ended']);

        $this->api()->postJson('/api/v1/admin/payments', $this->body($ended->driver, ['driver_contract_id' => $ended->id, 'payment_date' => '2026-07-10']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['payment_date' => 'La date est hors du contrat propriétaire (du 01/03/2026 au 30/06/2026) : aucune fiche ne compterait ce paiement.']);
        $this->api()->postJson('/api/v1/admin/payments', $this->body($ended->driver, ['driver_contract_id' => $ended->id, 'payment_date' => '2026-02-27']))
            ->assertStatus(422)->assertJsonValidationErrors('payment_date');
        $this->assertSame(0, Payment::count());
    }
}
