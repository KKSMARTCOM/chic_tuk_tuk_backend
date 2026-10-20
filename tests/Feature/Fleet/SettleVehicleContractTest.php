<?php

namespace Tests\Feature\Fleet;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\DriverContract;
use App\Models\Notification;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Kreait\Firebase\Contract\Messaging;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SettleVehicleContractTest extends TestCase
{
    use RefreshDatabase;

    /** Comme `AdminVehicleContractWritesApiTest` : un admin, ses permissions, son jeton. */
    private function asAdmin(): self
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'edit-contracts', 'guard_name' => 'web']));
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_settle_on_the_last_day_of_the_later_month(): void
    {
        $this->app->instance(Messaging::class, Mockery::mock(Messaging::class)->shouldIgnoreMissing());
        $contract = VehicleContract::factory()->create(['revenue_reached_month' => '2028-02-01', 'charges_reached_month' => '2028-03-01']);
        $driverContract = DriverContract::factory()->create(['vehicle_contract_id' => $contract->id, 'vehicle_id' => $contract->vehicle_id, 'status' => 'active']);

        $this->asAdmin()
            ->postJson("/api/v1/admin/vehicle-contracts/{$contract->id}/settle")
            ->assertOk()
            ->assertJsonPath('contract.status', 'completed')
            ->assertJsonPath('contract.end_date', '2028-03-31');

        $this->assertSame('active', $driverContract->refresh()->status);
        // `Notifier` est final : la notification se lit en base.
        $this->assertSame(
            'Le contrat de votre véhicule '.$contract->vehicle->vehicle_number.' a pris fin le 31/03/2028.',
            Notification::where('user_id', $contract->vehicle->owner_id)->sole()->message,
        );
        $this->assertDatabaseHas('activity_log', ['event' => 'vehicle.contract_completed', 'subject_id' => $contract->id]);
    }

    public function test_refused_with_a_single_target(): void
    {
        $contract = VehicleContract::factory()->create(['revenue_reached_month' => '2028-02-01']);

        $this->asAdmin()
            ->postJson("/api/v1/admin/vehicle-contracts/{$contract->id}/settle")
            ->assertStatus(409)->assertJsonPath('code', 'VEHICLE_CONTRACT_NOT_READY');

        $this->assertSame('active', $contract->refresh()->status);
    }

    public function test_completed_is_no_longer_chosen_in_the_form(): void
    {
        $contract = VehicleContract::factory()->create(['contract_months' => 30, 'total_amount' => 3_604_872]);

        $this->asAdmin()
            ->putJson("/api/v1/admin/vehicle-contracts/{$contract->id}", [
                'contract_months' => 30, 'total_amount' => 3_604_872, 'start_date' => '2026-10-01', 'status' => 'completed',
            ])
            ->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame('active', $contract->refresh()->status);
    }

    /** Relecture du 2026-10-12 : repassé « Actif », le contrat gardait sa fin et redevenait « À solder ». */
    public function test_a_settled_contract_does_not_leave_completed_through_the_form(): void
    {
        $contract = VehicleContract::factory()->create([
            'contract_months' => 30, 'total_amount' => 3_604_872, 'status' => 'completed', 'end_date' => '2026-08-31',
        ]);

        foreach (['active', 'cancelled', 'pending'] as $status) {
            $this->asAdmin()
                ->putJson("/api/v1/admin/vehicle-contracts/{$contract->id}", [
                    'contract_months' => 30, 'total_amount' => 3_604_872, 'start_date' => '2026-01-01', 'status' => $status,
                ])
                ->assertStatus(422)->assertJsonValidationErrors('status');
        }

        $this->assertSame('completed', $contract->refresh()->status);
    }

    public function test_settling_removes_the_later_draft_sheets(): void
    {
        // Relecture finale : cumuls atteints en mars, solde en mai ; le brouillon d'avril tombe.
        $contract = VehicleContract::factory()->create(['revenue_reached_month' => '2028-03-01', 'charges_reached_month' => '2028-03-01']);
        $april = RemunerationStatement::factory()->for($contract, 'contract')->create(['month' => '2028-04-01']);

        $this->asAdmin()->postJson("/api/v1/admin/vehicle-contracts/{$contract->id}/settle")->assertOk();

        $this->assertNull(RemunerationStatement::find($april->id));
    }

    public function test_settling_is_refused_while_a_later_sheet_is_validated(): void
    {
        $contract = VehicleContract::factory()->create(['revenue_reached_month' => '2028-03-01', 'charges_reached_month' => '2028-03-01']);
        RemunerationStatement::factory()->for($contract, 'contract')->validated()->create(['month' => '2028-04-01']);

        $this->asAdmin()->postJson("/api/v1/admin/vehicle-contracts/{$contract->id}/settle")
            ->assertStatus(409)->assertJsonPath('code', 'VEHICLE_CONTRACT_LATER_STATEMENT');

        $this->assertSame('active', $contract->refresh()->status);
    }
}
