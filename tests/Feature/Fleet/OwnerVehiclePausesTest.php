<?php

namespace Tests\Feature\Fleet;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\DriverContract;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OwnerVehiclePausesTest extends TestCase
{
    use RefreshDatabase;

    private function loginOwner(): array
    {
        $user = User::factory()->profil(Profil::Owner)->create([
            'password' => Hash::make('bon-mot-de-passe'),
        ]);
        Permission::findOrCreate('view-own-leaves', 'web');
        $user->givePermissionTo('view-own-leaves');

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');

        return [$user, $token];
    }

    public function test_le_vehicule_d_un_autre_proprietaire_renvoie_404(): void
    {
        [$owner, $token] = $this->loginOwner();
        $mien = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $autre = Vehicle::factory()->create();

        // ⚠️ La 200 sur MON véhicule est ce qui rend ce test discriminant : une route
        // absente répond 404 tout comme un refus de portée.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$mien->id}/pauses")
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$autre->id}/pauses")
            ->assertStatus(404);
    }

    public function test_les_pauses_sortent_de_la_plus_recente_a_la_plus_ancienne(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create();

        VehiclePause::factory()->forContract($contract)->create([
            'start_date' => '2026-03-01', 'end_date' => '2026-03-05',
        ]);
        VehiclePause::factory()->forContract($contract)->create([
            'start_date' => '2026-07-01', 'end_date' => '2026-07-03',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/pauses")
            ->assertOk();

        $this->assertSame(
            ['2026-07-01', '2026-03-01'],
            array_column($response->json('items'), 'start_date'),
        );
    }

    public function test_la_duree_compte_les_deux_bornes_et_reste_nulle_si_la_pause_court(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create();

        VehiclePause::factory()->forContract($contract)->create([
            'start_date' => '2026-03-01', 'end_date' => '2026-03-05',
        ]);
        VehiclePause::factory()->forContract($contract)->ongoing()->create([
            'start_date' => '2026-08-01',
        ]);

        $items = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/pauses")
            ->assertOk()
            ->json('items');

        // La pause en cours d'abord (tri décroissant) : durée inconnue.
        $this->assertNull($items[0]['days_count']);
        $this->assertNull($items[0]['end_date']);
        // Du 1er au 5 mars inclus : 5 jours, et non 4 — le tableau Blade ajoute 1.
        $this->assertSame(5, $items[1]['days_count']);
    }

    public function test_le_cumul_est_nul_sans_contrat_actif(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/pauses")
            ->assertOk()
            ->assertJsonPath('summary', null)
            ->assertJsonPath('items', []);
    }

    public function test_le_cumul_accompagne_l_historique_quand_un_contrat_est_actif(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        VehicleContract::factory()->forVehicle($vehicle)->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/pauses")
            ->assertOk()
            ->assertJsonStructure(['summary' => [
                'pause_allowance', 'pause_days_taken', 'pause_days_available',
                'pause_days_remaining', 'pause_overrun',
            ], 'items']);
    }

    public function test_pauses_beyond_the_allowance_show_as_an_overrun(): void
    {
        Carbon::setTestNow('2026-08-01 09:00:00');
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        // 1 mois de contrat : droit de 2 jours. Plusieurs agents peuvent se succéder sur
        // un même contrat, d'où l'absence de restriction : le dépassement s'affiche.
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create([
            'contract_months' => 1, 'start_date' => '2026-07-01',
        ]);
        VehiclePause::factory()->forContract($contract)->create([
            'start_date' => '2026-07-06', 'end_date' => '2026-07-10', 'reason_type' => 'agent_leave',
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/pauses")
            ->assertOk()
            ->assertJsonPath('summary.pause_allowance', 2)
            ->assertJsonPath('summary.pause_days_taken', 5)
            ->assertJsonPath('summary.pause_days_remaining', 0)
            ->assertJsonPath('summary.pause_overrun', 3);

        Carbon::setTestNow();
    }

    /**
     * Une pause d'agent saisie après coup (`AddHistoricalLeave`) ne crée pas de pause
     * véhicule : la liste la lisait seulement dans `vehicle_pauses`, et la manquait alors
     * que le solde la compte (défaut du 2026-10-05).
     */
    public function test_les_pauses_d_agent_sans_pause_vehicule_figurent_dans_l_historique(): void
    {
        [$owner, $token] = $this->loginOwner();
        $vehicle = Vehicle::factory()->create(['owner_id' => $owner->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create(['start_date' => '2026-01-01']);
        $agentContract = DriverContract::factory()->forVehicleContract($contract)->create();

        // Pause d'agent ordinaire : sa pause véhicule automatique la représente déjà.
        $autoPause = VehiclePause::factory()->forContract($contract)->create([
            'start_date' => '2026-05-04', 'end_date' => '2026-05-06',
            'reason_type' => 'agent_leave', 'is_auto' => true,
            'reason_notes' => 'Suite à la demande de pause de l’agent.',
        ]);
        LeaveRequest::factory()->create([
            'driver_id' => $agentContract->driver_id, 'driver_contract_id' => $agentContract->id,
            'start_date' => '2026-05-04', 'end_date' => '2026-05-06', 'vehicle_pause_id' => $autoPause->id,
        ]);
        // Saisie après coup : aucune pause véhicule.
        $historical = LeaveRequest::factory()->create([
            'driver_id' => $agentContract->driver_id, 'driver_contract_id' => $agentContract->id,
            'start_date' => '2026-07-10', 'end_date' => '2026-07-16',
        ]);
        // Pause véhicule disparue : la pause d'agent reste comptée au solde.
        $orphan = LeaveRequest::factory()->create([
            'driver_id' => $agentContract->driver_id, 'driver_contract_id' => $agentContract->id,
            'start_date' => '2026-03-02', 'end_date' => '2026-03-03', 'vehicle_pause_id' => (string) Str::uuid(),
        ]);
        // Ni une demande en attente, ni une demande refusée, ni la pause d'un autre véhicule.
        LeaveRequest::factory()->pending()->create([
            'driver_id' => $agentContract->driver_id, 'driver_contract_id' => $agentContract->id,
        ]);
        LeaveRequest::factory()->create([
            'driver_id' => $agentContract->driver_id, 'driver_contract_id' => $agentContract->id,
            'status' => 'rejected', 'start_date' => '2026-06-01', 'end_date' => '2026-06-02',
        ]);
        $elsewhere = DriverContract::factory()->create();
        LeaveRequest::factory()->create([
            'driver_id' => $elsewhere->driver_id, 'driver_contract_id' => $elsewhere->id,
            'start_date' => '2026-06-15', 'end_date' => '2026-06-16',
        ]);
        VehiclePause::factory()->forContract($contract)->create([
            'start_date' => '2026-04-01', 'end_date' => '2026-04-02', 'reason_type' => 'technical',
        ]);

        $items = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/owner/vehicles/{$vehicle->id}/pauses")
            ->assertOk()
            ->json('items');

        $this->assertSame(
            ['2026-07-10', '2026-05-04', '2026-04-01', '2026-03-02'],
            array_column($items, 'start_date'),
        );
        $this->assertSame([
            'id' => $historical->id,
            'start_date' => '2026-07-10',
            'end_date' => '2026-07-16',
            'reason_type' => 'agent_leave',
            'reason_label' => 'Pause agent',
            // Le motif personnel de l'agent ne regarde pas le propriétaire.
            'reason_notes' => null,
            'is_auto' => true,
            // Même formule que les pauses véhicule : les deux bornes au calendrier.
            'days_count' => 7,
        ], $items[0]);
        $this->assertSame($autoPause->id, $items[1]['id']);
        $this->assertSame($orphan->id, $items[3]['id']);
    }
}
