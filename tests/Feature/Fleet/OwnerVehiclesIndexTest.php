<?php

namespace Tests\Feature\Fleet;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OwnerVehiclesIndexTest extends TestCase
{
    use RefreshDatabase;

    /** Connecte un utilisateur par l'API et renvoie [$user, $token]. */
    private function login(Profil $profil, array $permissions = []): array
    {
        $user = User::factory()->profil($profil)->create([
            'password' => Hash::make('bon-mot-de-passe'),
        ]);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');

        return [$user, $token];
    }

    public function test_sans_jeton_la_reponse_est_un_401_json(): void
    {
        // Et surtout PAS une 302 vers /login, que l'ancienne branche Blade de
        // CheckPermission renvoyait (retirée le 2026-09-27).
        $this->getJson('/api/v1/owner/vehicles')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_un_jeton_d_un_autre_profil_est_refuse(): void
    {
        [, $token] = $this->login(Profil::Admin, ['view-own-vehicles']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/owner/vehicles')
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_le_bon_profil_sans_la_permission_est_refuse(): void
    {
        [, $token] = $this->login(Profil::Owner);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/owner/vehicles')
            ->assertStatus(403);
    }

    public function test_ne_renvoie_que_les_vehicules_de_l_appelant(): void
    {
        [$owner, $token] = $this->login(Profil::Owner, ['view-own-vehicles']);

        $mien = Vehicle::factory()->create(['owner_id' => $owner->id]);
        Vehicle::factory()->create(); // celui d'un autre propriétaire

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/owner/vehicles')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $mien->id)
            ->assertJsonPath('0.vehicle_number', $mien->vehicle_number);
    }

    public function test_un_vehicule_sans_contrat_actif_renvoie_un_contrat_nul(): void
    {
        [$owner, $token] = $this->login(Profil::Owner, ['view-own-vehicles']);

        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        VehicleContract::factory()->forVehicle($vehicle)->completed()->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/owner/vehicles')
            ->assertOk()
            ->assertJsonPath('0.contract', null)
            ->assertJsonPath('0.state', 'active');
    }

    public function test_expose_l_avancement_du_contrat_actif(): void
    {
        [$owner, $token] = $this->login(Profil::Owner, ['view-own-vehicles']);

        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        VehicleContract::factory()->forVehicle($vehicle)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/owner/vehicles')
            ->assertOk()
            ->assertJsonPath('0.contract.contract_months', 24)
            ->assertJsonStructure([
                ['id', 'vehicle_number', 'vehicle_type', 'state', 'pause_reason_label', 'contract' => [
                    'contract_months', 'months_elapsed', 'months_remaining',
                    'progress_percentage', 'remaining_amount',
                ]],
            ]);
    }

    public function test_une_liste_vide_est_un_tableau_vide_et_non_une_erreur(): void
    {
        [, $token] = $this->login(Profil::Owner, ['view-own-vehicles']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/owner/vehicles')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_each_vehicle_carries_its_state_and_its_totals(): void
    {
        Carbon::setTestNow('2026-08-01 09:00:00');
        [$owner, $token] = $this->login(Profil::Owner, ['view-own-vehicles']);
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create(['start_date' => '2026-07-01', 'total_amount' => 3_100_000]);
        VehiclePause::factory()->forContract($contract)->ongoing()->create(['start_date' => '2026-07-31', 'reason_type' => 'agent_change']);
        Payment::factory()->onDay('2026-07-01')->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 310_000]);
        Payment::factory()->onDay('2026-07-02')->status('pending')->create(['vehicle_contract_id' => $contract->id, 'net_amount' => 5_871]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/owner/vehicles')
            ->assertOk()
            ->assertJsonPath('0.state', 'immobilized')
            ->assertJsonPath('0.contract.paid_amount', 310_000)
            ->assertJsonPath('0.contract.pending_amount', 5_871)
            ->assertJsonPath('0.contract.revenue_progress', 10)
            ->assertJsonPath('0.contract.pauses.pause_allowance', 48)
            ->assertJsonMissingPath('0.is_on_pause');

        Carbon::setTestNow();
    }
}
