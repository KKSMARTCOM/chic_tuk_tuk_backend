<?php

namespace Tests\Feature\Fleet;

use App\Domains\Finance\Application\Actions\GenerateDailyContractPayments;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\DriverContract;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehicleContractTerm;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Les durées et montants des contrats véhicule, réglés par l'administration (2026-09-29).
 *
 * ⚠️ Le cœur du lot : un contrat FIGE son versement et sa taxe journaliers à sa création.
 * Jusque-là, la génération du soir les relisait dans des constantes à chaque passage :
 * rendre les montants réglables sans les figer aurait changé les versements de tous les
 * contrats en cours.
 */
class VehicleContractSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function login(array $permissions): string
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');
    }

    private function asBearer(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /** @return array<string, mixed> */
    private function settings(array $overrides = []): array
    {
        return array_merge([
            'terms' => [
                ['months' => 24, 'total_amount' => 3_100_000, 'daily_amount' => 6112, 'daily_tax' => 241],
                ['months' => 30, 'total_amount' => 3_604_872, 'daily_amount' => 5691, 'daily_tax' => 229],
                ['months' => 36, 'total_amount' => 4_049_100, 'daily_amount' => 5251, 'daily_tax' => 211],
            ],
            'unlimited_internet' => 5_000,
            'spotify_premium' => 2_500,
            'manager_remuneration' => 20_000,
        ], $overrides);
    }

    private function createContract(int $months): TestResponse
    {
        $vehicle = Vehicle::factory()->create();

        return $this->asBearer($this->login(['create-contracts']))
            ->postJson('/api/v1/admin/vehicle-contracts', [
                'vehicle_id' => $vehicle->id,
                'contract_months' => $months,
                'total_amount' => 3_000_000,
                'start_date' => '2026-10-01',
            ]);
    }

    // ----- Lecture et écriture des réglages ----------------------------------------------

    public function test_the_settings_start_from_the_historical_amounts(): void
    {
        $this->asBearer($this->login(['manage-business-settings']))
            ->getJson('/api/v1/admin/settings/vehicle-contracts')
            ->assertOk()
            ->assertJsonPath('terms.0', ['months' => 24, 'total_amount' => 3_100_000, 'daily_amount' => 6112, 'daily_tax' => 241])
            ->assertJsonPath('terms.2.months', 36)
            ->assertJsonPath('manager_remuneration', 20_000);
    }

    public function test_only_the_business_settings_permission_opens_them(): void
    {
        // `manage-settings`, que porte aussi l'utilisateur, ne suffit pas.
        $token = $this->login(['manage-settings', 'create-contracts', 'edit-contracts']);

        $this->asBearer($token)->getJson('/api/v1/admin/settings/vehicle-contracts')->assertForbidden();
        $this->asBearer($token)->putJson('/api/v1/admin/settings/vehicle-contracts', $this->settings())->assertForbidden();
    }

    public function test_durations_are_updated_added_and_removed(): void
    {
        $response = $this->asBearer($this->login(['manage-business-settings']))
            ->putJson('/api/v1/admin/settings/vehicle-contracts', $this->settings([
                'terms' => [
                    ['months' => 24, 'total_amount' => 3_200_000, 'daily_amount' => 6300, 'daily_tax' => 250],
                    ['months' => 36, 'total_amount' => 4_049_100, 'daily_amount' => 5251, 'daily_tax' => 211],
                    ['months' => 48, 'total_amount' => 5_000_000, 'daily_amount' => 4900, 'daily_tax' => 190],
                ],
                'spotify_premium' => 3_000,
            ]))
            ->assertOk();

        $this->assertEquals([24, 36, 48], collect($response->json('terms'))->pluck('months')->all());
        $response->assertJsonPath('terms.0.daily_amount', 6300)->assertJsonPath('spotify_premium', 3_000);
        $this->assertDatabaseMissing('vehicle_contract_terms', ['months' => 30]);
    }

    public function test_invalid_settings_are_refused_field_by_field(): void
    {
        $token = $this->login(['manage-business-settings']);

        $this->asBearer($token)
            ->putJson('/api/v1/admin/settings/vehicle-contracts', $this->settings(['terms' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['terms']);

        $this->asBearer($token)
            ->putJson('/api/v1/admin/settings/vehicle-contracts', $this->settings(['terms' => [
                ['months' => 24, 'total_amount' => 3_100_000, 'daily_amount' => 6112, 'daily_tax' => 241],
                ['months' => 24, 'total_amount' => 3_100_000, 'daily_amount' => 6112, 'daily_tax' => 6112],
            ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['terms.1.months', 'terms.1.daily_tax']);

        $this->assertSame(3, VehicleContractTerm::count());
    }

    // ----- Les contrats figent leurs montants --------------------------------------------

    public function test_a_new_contract_freezes_the_daily_amounts_of_its_duration(): void
    {
        $this->createContract(30)->assertCreated();

        $contract = VehicleContract::query()->latest()->firstOrFail();
        $this->assertEquals(5691, $contract->daily_amount);
        $this->assertEquals(229, $contract->daily_tax);
    }

    public function test_changing_the_settings_leaves_existing_contracts_untouched(): void
    {
        $this->createContract(24)->assertCreated();
        $contract = VehicleContract::query()->latest()->firstOrFail();

        VehicleContractTerm::query()->where('months', 24)->update(['daily_amount' => 9999, 'daily_tax' => 999]);

        $this->assertEquals(6112, $contract->fresh()->daily_amount);
        $this->assertEquals(241, $contract->fresh()->daily_tax);
    }

    public function test_a_duration_that_is_not_offered_is_refused(): void
    {
        // Le cas réel : une durée « autre » n'avait aucun versement journalier, et la
        // génération créait chaque soir un paiement de 0 FCFA.
        $this->createContract(20)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contract_months']);

        $this->assertSame(0, VehicleContract::count());
    }

    public function test_a_contract_keeps_a_duration_that_is_no_longer_offered(): void
    {
        $vehicle = Vehicle::factory()->create();
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create(['contract_months' => 30, 'daily_amount' => 5691, 'daily_tax' => 229]);
        VehicleContractTerm::query()->where('months', 30)->delete();

        $this->asBearer($this->login(['edit-contracts']))
            ->putJson("/api/v1/admin/vehicle-contracts/{$contract->id}", [
                'contract_months' => 30,
                'total_amount' => 3_604_872,
                'start_date' => '2026-06-01',
                'status' => 'active',
                'notes' => 'Note corrigée',
            ])
            ->assertOk();

        $this->assertEquals(5691, $contract->fresh()->daily_amount);
    }

    public function test_changing_the_duration_of_a_contract_takes_the_amounts_of_the_new_one(): void
    {
        $vehicle = Vehicle::factory()->create();
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create();

        $this->asBearer($this->login(['edit-contracts']))
            ->putJson("/api/v1/admin/vehicle-contracts/{$contract->id}", [
                'contract_months' => 36,
                'total_amount' => 4_049_100,
                'start_date' => '2026-06-01',
                'status' => 'active',
            ])
            ->assertOk();

        $this->assertEquals(5251, $contract->fresh()->daily_amount);
        $this->assertEquals(211, $contract->fresh()->daily_tax);
    }

    // ----- Les paiements lisent le contrat -----------------------------------------------

    public function test_the_daily_generation_uses_the_frozen_amounts(): void
    {
        $vehicle = Vehicle::factory()->create();
        $vehicleContract = VehicleContract::factory()->forVehicle($vehicle)->create();
        $driverContract = DriverContract::factory()->forVehicleContract($vehicleContract)->create();
        VehicleContractTerm::query()->where('months', 24)->update(['daily_amount' => 9999, 'daily_tax' => 999]);

        app(GenerateDailyContractPayments::class)(Carbon::parse('2026-09-28'));

        $payment = Payment::query()->where('driver_contract_id', $driverContract->id)->firstOrFail();
        $this->assertEquals(6112, $payment->amount);
        $this->assertEquals(6112 - 241, $payment->net_amount);
    }

    public function test_a_contract_without_daily_amount_generates_no_zero_payment(): void
    {
        $vehicle = Vehicle::factory()->create();
        $vehicleContract = VehicleContract::factory()->forVehicle($vehicle)->create([
            'contract_months' => 20, 'daily_amount' => null, 'daily_tax' => null,
        ]);
        $driverContract = DriverContract::factory()->forVehicleContract($vehicleContract)->create();

        $result = app(GenerateDailyContractPayments::class)(Carbon::parse('2026-09-28'));

        $this->assertSame(0, Payment::query()->where('driver_contract_id', $driverContract->id)->count());
        $this->assertSame(0, $result['generated']);
    }

    public function test_a_manual_contract_payment_deducts_the_tax_frozen_on_the_contract(): void
    {
        $vehicle = Vehicle::factory()->create();
        $vehicleContract = VehicleContract::factory()->forVehicle($vehicle)->create();
        $driverContract = DriverContract::factory()->forVehicleContract($vehicleContract)->create();
        VehicleContractTerm::query()->where('months', 24)->update(['daily_tax' => 999]);

        $this->asBearer($this->login(['create-payments']))
            ->postJson('/api/v1/admin/payments', [
                'driver_id' => $driverContract->driver_id,
                'payment_type' => 'contract',
                'amount' => 10_000,
                'payment_method' => 'mobile_money',
                'payment_date' => '2026-09-28',
            ])
            ->assertCreated()
            ->assertJsonPath('payment.net_amount', 10_000 - 241);
    }
}
