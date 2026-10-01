<?php

namespace Tests\Feature\Finance;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\DriverContract;
use App\Models\LeaveRequest;
use App\Models\Payment;
use App\Models\User;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Générer les paiements d'une période pour un agent, en place ou parti (spec 2026-10-01, §3). */
class ContractPaymentGenerationApiTest extends TestCase
{
    use RefreshDatabase;

    private VehicleContract $vehicleContract;

    private DriverContract $resigned;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-01 09:00:00');
        $this->vehicleContract = VehicleContract::factory()->create(['start_date' => '2026-03-01', 'daily_amount' => 6112, 'daily_tax' => 241]);
        // L'agent A, parti le 13/03.
        $this->resigned = DriverContract::factory()->forVehicleContract($this->vehicleContract)->create([
            'start_date' => '2026-03-02', 'end_date' => '2026-03-13', 'status' => 'ended', 'end_reason' => 'demission',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function login(array $permissions = ['create-payments']): self
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function url(string $action, ?DriverContract $contract = null): string
    {
        return '/api/v1/admin/driver-contracts/'.($contract ?? $this->resigned)->id.'/payments/'.$action;
    }

    public function test_the_preview_writes_nothing_and_bounds_the_period_to_the_contract(): void
    {
        $response = $this->login()->postJson($this->url('preview'), ['from' => '2026-03-01', 'to' => '2026-03-31'])->assertOk();

        $this->assertSame(10, $response->json('counts.to_generate'));
        $this->assertEquals(10 * 5871, $response->json('total_net'));
        $this->assertEquals(6112, $response->json('daily_amount'));
        $this->assertEquals(5871, $response->json('daily_net_amount'));
        $this->assertSame('2026-03-02', $response->json('from'));
        $this->assertSame('2026-03-13', $response->json('to'));
        $this->assertSame(0, Payment::count());
    }

    public function test_generation_attaches_the_payments_to_the_agents_own_contracts(): void
    {
        // Un autre contrat véhicule est actif sur le véhicule depuis : il ne doit pas servir.
        VehicleContract::factory()->create(['vehicle_id' => $this->vehicleContract->vehicle_id, 'start_date' => '2026-06-01']);

        $this->login()->postJson($this->url('generate'), ['from' => '2026-03-01', 'to' => '2026-03-31'])
            ->assertOk()->assertJson(['created' => 10]);

        $payments = Payment::orderBy('payment_date')->get();
        $this->assertCount(10, $payments);
        $first = $payments->first();
        $this->assertSame('2026-03-02', $first->payment_date->toDateString());
        $this->assertSame('2026-03-01', $first->payment_month->toDateString());
        $this->assertSame([$this->vehicleContract->id, $this->resigned->id, $this->resigned->driver_id, 'pending', 'contract', 'other'],
            [$first->vehicle_contract_id, $first->driver_contract_id, $first->driver_id, $first->status, $first->payment_type, $first->payment_method]);
        $this->assertEquals(5871, $first->net_amount);
        $this->assertSame('Paiement généré — période du 01/03/2026 au 31/03/2026', $first->notes);
        $this->assertNull($first->collected_on);
        $this->assertSame(1, Activity::where('event', 'payment.period_generated')->count());
    }

    public function test_historical_agent_pauses_and_immobilizations_are_skipped(): void
    {
        LeaveRequest::factory()->create([
            'driver_id' => $this->resigned->driver_id, 'driver_contract_id' => $this->resigned->id,
            'start_date' => '2026-03-04', 'end_date' => '2026-03-05', 'status' => 'completed',
        ]);
        VehiclePause::factory()->forContract($this->vehicleContract)->create(['start_date' => '2026-03-09', 'end_date' => '2026-03-09', 'reason_type' => 'technical']);

        $response = $this->login()->postJson($this->url('preview'), ['from' => '2026-03-01', 'to' => '2026-03-31'])->assertOk();

        $this->assertSame(2, $response->json('counts.agent_pause'));
        $this->assertSame(1, $response->json('counts.immobilized'));
        $this->assertSame(7, $response->json('counts.to_generate'));
    }

    public function test_paid_days_are_skipped_and_cancelled_ones_only_when_ticked(): void
    {
        $base = ['vehicle_contract_id' => $this->vehicleContract->id, 'driver_contract_id' => $this->resigned->id, 'driver_id' => $this->resigned->driver_id];
        Payment::factory()->onDay('2026-03-02')->status('completed')->create($base);
        Payment::factory()->onDay('2026-03-03')->status('cancelled')->create($base);
        Payment::factory()->onDay('2026-03-04')->status('cancelled')->create($base);

        $this->login()->postJson($this->url('generate'), ['from' => '2026-03-01', 'to' => '2026-03-31', 'regenerate_cancelled' => ['2026-03-04']])
            ->assertOk()->assertJson(['created' => 8]);

        $this->assertSame(1, Payment::whereDate('payment_date', '2026-03-04')->where('status', 'pending')->count());
        $this->assertSame(0, Payment::whereDate('payment_date', '2026-03-03')->where('status', 'pending')->count());
        $this->assertSame(1, Payment::whereDate('payment_date', '2026-03-02')->count());
    }

    public function test_a_ticked_date_that_is_not_cancelled_is_refused(): void
    {
        $this->login()->postJson($this->url('generate'), ['from' => '2026-03-01', 'to' => '2026-03-31', 'regenerate_cancelled' => ['2026-03-05']])
            ->assertStatus(422)->assertJsonValidationErrors('regenerate_cancelled');
        $this->assertSame(0, Payment::count());
    }

    public function test_a_day_paid_since_the_preview_is_reported_as_skipped(): void
    {
        Payment::factory()->onDay('2026-03-02')->status('pending')->create([
            'vehicle_contract_id' => $this->vehicleContract->id, 'driver_contract_id' => $this->resigned->id, 'driver_id' => $this->resigned->driver_id,
        ]);

        $this->login()->postJson($this->url('generate'), ['from' => '2026-03-01', 'to' => '2026-03-31', 'expected' => ['2026-03-02', '2026-03-03']])
            ->assertOk()->assertJson(['created' => 9, 'skipped' => ['2026-03-02']]);
    }

    public function test_generating_twice_creates_no_duplicate(): void
    {
        $api = $this->login();
        $api->postJson($this->url('generate'), ['from' => '2026-03-01', 'to' => '2026-03-31'])->assertJson(['created' => 10]);
        $api->postJson($this->url('generate'), ['from' => '2026-03-01', 'to' => '2026-03-31'])->assertJson(['created' => 0]);
        $this->assertSame(10, Payment::count());
    }

    public function test_errors(): void
    {
        $api = $this->login();
        $api->postJson($this->url('preview'), ['from' => '2026-03-31', 'to' => '2026-03-01'])->assertStatus(422);
        $api->postJson($this->url('preview'), ['from' => '2026-05-01', 'to' => '2026-05-31'])
            ->assertStatus(422)->assertJsonValidationErrors(['from' => 'La période est hors du contrat de l\'agent.']);

        $this->vehicleContract->update(['daily_amount' => null]);
        $api->postJson($this->url('generate'), ['from' => '2026-03-01', 'to' => '2026-03-31'])
            ->assertStatus(409)->assertJsonPath('code', 'CONTRACT_WITHOUT_DAILY_AMOUNT');
    }

    public function test_the_permission_is_required(): void
    {
        $this->login([])->postJson($this->url('preview'), ['from' => '2026-03-01', 'to' => '2026-03-31'])->assertForbidden();
        $this->login([])->postJson($this->url('generate'), ['from' => '2026-03-01', 'to' => '2026-03-31'])->assertForbidden();
    }

    public function test_a_day_already_paid_by_another_agent_of_the_same_vehicle_is_not_generated_again(): void
    {
        // Le jour où A part et B arrive : le véhicule ne rapporte qu'une fois ce jour-là.
        $next = DriverContract::factory()->forVehicleContract($this->vehicleContract)->create(['start_date' => '2026-03-13']);
        Payment::factory()->onDay('2026-03-13')->status('completed')->create([
            'vehicle_contract_id' => $this->vehicleContract->id, 'driver_contract_id' => $next->id, 'driver_id' => $next->driver_id,
        ]);

        $response = $this->login()->postJson($this->url('preview'), ['from' => '2026-03-13', 'to' => '2026-03-13'])->assertOk();

        $this->assertSame('paid', $response->json('days.0.class'));
        $this->login()->postJson($this->url('generate'), ['from' => '2026-03-13', 'to' => '2026-03-13'])->assertJson(['created' => 0]);
    }

    public function test_the_vehicle_contract_is_locked_during_generation(): void
    {
        $locked = false;
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$locked) {
            if (str_contains($query->sql, '"vehicle_contracts"') && str_contains(strtolower($query->sql), 'for update')) {
                $locked = true;
            }
        });

        $this->login()->postJson($this->url('generate'), ['from' => '2026-03-02', 'to' => '2026-03-02'])->assertJson(['created' => 1]);
        $this->assertTrue($locked);
    }
}
