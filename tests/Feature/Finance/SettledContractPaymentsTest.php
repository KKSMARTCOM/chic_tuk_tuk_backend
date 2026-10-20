<?php

namespace Tests\Feature\Finance;

use App\Domains\Finance\Application\Actions\GenerateDailyContractPayments;
use App\Domains\Finance\Application\Actions\PlanDriverContractPayments;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\DriverContract;
use App\Models\Payment;
use App\Models\User;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Kreait\Firebase\Contract\Messaging;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SettledContractPaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Messaging::class, Mockery::mock(Messaging::class)->shouldIgnoreMissing());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function settledWithAnAgent(): DriverContract
    {
        $contract = VehicleContract::factory()->create([
            'status' => 'completed', 'start_date' => '2026-01-01', 'end_date' => '2026-08-31',
            'revenue_reached_month' => '2026-08-01', 'charges_reached_month' => '2026-08-01',
        ]);

        return DriverContract::factory()->create([
            'vehicle_contract_id' => $contract->id, 'vehicle_id' => $contract->vehicle_id,
            'start_date' => '2026-01-01', 'contract_months' => 24, 'status' => 'active',
        ]);
    }

    public function test_the_evening_generation_continues_after_the_end(): void
    {
        Carbon::setTestNow('2026-09-14 20:00:00'); // un lundi
        $agent = $this->settledWithAnAgent();

        app(GenerateDailyContractPayments::class)();

        $this->assertDatabaseHas('payments', ['driver_contract_id' => $agent->id, 'payment_date' => '2026-09-14']);
    }

    public function test_the_owner_contract_end_no_longer_bounds_the_period(): void
    {
        Carbon::setTestNow('2026-09-30 20:00:00');
        $agent = $this->settledWithAnAgent();

        $plan = app(PlanDriverContractPayments::class)($agent, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        $this->assertNotEmpty($plan->days);
    }

    public function test_a_payment_after_the_end_is_tagged_agency_for_the_admin(): void
    {
        $agent = $this->settledWithAnAgent();
        Payment::factory()->onDay('2026-09-14')->status('completed')->create([
            'vehicle_contract_id' => $agent->vehicle_contract_id, 'driver_contract_id' => $agent->id,
        ]);
        Payment::factory()->onDay('2026-08-14')->status('completed')->create([
            'vehicle_contract_id' => $agent->vehicle_contract_id, 'driver_contract_id' => $agent->id,
        ]);

        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'view-payments', 'guard_name' => 'web']));
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        $rows = collect($this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/admin/payments')->json('payments'))
            ->keyBy('payment_date');

        $this->assertTrue($rows['2026-09-14']['is_agency']);
        $this->assertFalse($rows['2026-08-14']['is_agency']);
    }

    /** Réglé le 2026-10-20 : la même règle que chez le propriétaire (`payment_date` est NOT NULL en base). */
    public function test_payments_left_out_of_the_final_statement_are_tagged_agency(): void
    {
        $agent = $this->settledWithAnAgent();
        \App\Models\RemunerationStatement::factory()->validated()->create([
            'vehicle_contract_id' => $agent->vehicle_contract_id, 'month' => '2026-08-01', 'issued_on' => '2026-09-03',
        ]);
        $make = fn (string $day, string $status, array $extra = []) => Payment::factory()->status($status)->create([
            'vehicle_contract_id' => $agent->vehicle_contract_id, 'driver_contract_id' => $agent->id,
            'payment_date' => $day, 'payment_month' => '2026-08-01', 'reference_number' => $day,
        ] + $extra);
        $make('2026-08-12', 'completed', ['collected_on' => '2026-08-12']);
        $make('2026-08-27', 'completed', ['collected_on' => '2026-09-10']);
        $make('2026-08-28', 'pending');

        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'view-payments', 'guard_name' => 'web']));
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        $rows = collect($this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/admin/payments')->json('payments'))
            ->mapWithKeys(fn ($row) => [$row['reference_number'] => $row['is_agency']]);

        $this->assertSame(['2026-08-12' => false, '2026-08-27' => true, '2026-08-28' => true], $rows->sortKeys()->all());
    }

    public function test_an_old_hand_settled_contract_does_not_resume_the_evening_generation(): void
    {
        // Relecture finale : « Soldé » choisi à la main avant le 2026-10-12, sans cumuls atteints.
        Carbon::setTestNow('2026-09-14 20:00:00');
        $contract = VehicleContract::factory()->create(['status' => 'completed', 'start_date' => '2026-01-01', 'end_date' => null]);
        $agent = DriverContract::factory()->create([
            'vehicle_contract_id' => $contract->id, 'vehicle_id' => $contract->vehicle_id,
            'start_date' => '2026-01-01', 'contract_months' => 24, 'status' => 'active',
        ]);

        app(GenerateDailyContractPayments::class)();

        $this->assertDatabaseMissing('payments', ['driver_contract_id' => $agent->id]);
    }
}
