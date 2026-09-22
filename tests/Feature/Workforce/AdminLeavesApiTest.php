<?php

namespace Tests\Feature\Workforce;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Les trois lectures de congés de l'espace administration.
 *
 * `AdminLeavesListTest` couvre le calcul et les filtres ; ce fichier-ci couvre le
 * CONTRAT d'API et les gardes — qui entre, qui est refusé, et ce que le JSON contient.
 */
class AdminLeavesApiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: string} */
    private function connecter(Profil $profil, array $permissions): array
    {
        $user = User::factory()->profil($profil)->create(['password' => Hash::make('bon-mot-de-passe')]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');

        return [$user, $token];
    }

    /** Voir NotificationsApiTest : le garde mémorise l'utilisateur entre deux requêtes. */
    private function entete(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function agent(string $nom = 'Awa Dossou'): Driver
    {
        $user = User::factory()->profil(Profil::Driver)->create(['name' => $nom]);
        $driver = Driver::factory()->create(['user_id' => $user->id]);
        DriverContract::factory()->create([
            'driver_id' => $driver->id,
            'start_date' => now()->subMonths(6)->startOfDay(),
            'contract_months' => 24,
            'status' => 'active',
        ]);

        return $driver->refresh();
    }

    // ----- Les gardes --------------------------------------------------------

    public function test_view_leaves_ouvre_la_liste(): void
    {
        [, $token] = $this->connecter(Profil::Admin, ['view-leaves']);

        $this->entete($token)->getJson('/api/v1/admin/leaves')->assertOk();
    }

    public function test_sans_view_leaves_la_liste_est_refusee(): void
    {
        [, $token] = $this->connecter(Profil::Admin, ['view-dashboard']);

        $this->entete($token)->getJson('/api/v1/admin/leaves')->assertForbidden();
    }

    public function test_la_file_des_demandes_exige_sa_propre_permission(): void
    {
        // ⚠️ `view-leaves` ne suffit PAS : le Blade distingue les deux, et un rôle peut
        // consulter les soldes sans avoir à trancher les demandes.
        [, $token] = $this->connecter(Profil::Admin, ['view-leaves']);

        $this->entete($token)->getJson('/api/v1/admin/leave-requests')->assertForbidden();

        [, $autre] = $this->connecter(Profil::Admin, ['view-leave-requests']);
        $this->entete($autre)->getJson('/api/v1/admin/leave-requests')->assertOk();
    }

    public function test_un_agent_ne_peut_pas_ouvrir_les_conges_de_l_administration(): void
    {
        // Même avec `view-leaves`, qui est une permission d'administration :
        // `abilities:admin` écarte un jeton émis pour un compte agent.
        [, $token] = $this->connecter(Profil::Driver, ['view-leaves']);

        $this->entete($token)->getJson('/api/v1/admin/leaves')->assertForbidden();
    }

    // ----- Le contrat --------------------------------------------------------

    public function test_la_liste_porte_les_deux_identifiants(): void
    {
        // ⚠️ Le Blade expose l'identifiant du COMPTE, l'API celui de l'AGENT. Les deux
        // sont des uuid et se confondent sans rien casser de visible : ils sont donc
        // nommés tous les deux.
        $agent = $this->agent();
        [, $token] = $this->connecter(Profil::Admin, ['view-leaves']);

        $this->entete($token)->getJson('/api/v1/admin/leaves')
            ->assertOk()
            ->assertJsonPath('0.id', $agent->id)
            ->assertJsonPath('0.user_id', $agent->user_id)
            ->assertJsonPath('0.name', 'Awa Dossou');
    }

    public function test_le_dossier_d_un_agent_porte_ses_trois_listes(): void
    {
        $agent = $this->agent();
        $contrat = $agent->activeDriverContract;

        LeaveRequest::factory()->pending()->create([
            'driver_id' => $agent->id, 'driver_contract_id' => $contrat->id, 'requested_days' => 2,
        ]);
        LeaveRequest::factory()->create([
            'driver_id' => $agent->id, 'driver_contract_id' => $contrat->id,
            'status' => 'completed', 'start_date' => now()->subMonth()->toDateString(),
            'requested_days' => 3, 'effective_days' => 3,
        ]);

        [, $token] = $this->connecter(Profil::Admin, ['view-leaves']);

        $this->entete($token)->getJson("/api/v1/admin/leaves/{$agent->id}")
            ->assertOk()
            ->assertJsonCount(1, 'pending')
            ->assertJsonCount(1, 'history')
            ->assertJsonPath('ongoing', null)
            ->assertJsonPath('leave_days_used', 3);
    }

    public function test_un_agent_introuvable_donne_404(): void
    {
        [, $token] = $this->connecter(Profil::Admin, ['view-leaves']);

        $this->entete($token)
            ->getJson('/api/v1/admin/leaves/01930000-0000-7000-8000-000000000000')
            ->assertNotFound();
    }

    public function test_la_file_montre_le_solde_de_l_agent_avec_sa_demande(): void
    {
        // ⚠️ L'information qui manque le plus pour trancher : sans elle, il faut ouvrir
        // la fiche de l'agent à chaque demande.
        $agent = $this->agent('Kofi Mensah');
        LeaveRequest::factory()->pending()->create([
            'driver_id' => $agent->id,
            'driver_contract_id' => $agent->activeDriverContract->id,
            'requested_days' => 2,
        ]);

        [, $token] = $this->connecter(Profil::Admin, ['view-leave-requests']);

        $this->entete($token)->getJson('/api/v1/admin/leave-requests')
            ->assertOk()
            ->assertJsonPath('0.driver_name', 'Kofi Mensah')
            ->assertJsonPath('0.driver_id', $agent->id)
            // Sept mois crédités à deux jours, deux jours demandés en attente.
            ->assertJsonPath('0.available_leave_days', 12);
    }

    public function test_l_historique_distingue_la_saisie_administrative_de_la_pause_vecue(): void
    {
        // ⚠️ C'est ce drapeau, et non le statut, qui dit si une pause terminée se corrige
        // et se supprime : `DeleteLeave` refuse une pause terminée issue d'une demande
        // d'agent. Sans lui dans le JSON, l'écran proposerait « Modifier » et
        // « Supprimer » sur des pauses que l'API rejette, et le refus ne se découvrirait
        // qu'au clic.
        $agent = $this->agent();
        $contrat = $agent->activeDriverContract;

        LeaveRequest::factory()->create([
            'driver_id' => $agent->id, 'driver_contract_id' => $contrat->id,
            'status' => 'completed', 'source' => 'admin_historical',
            'start_date' => now()->subMonths(2)->toDateString(),
            'requested_days' => 2, 'effective_days' => 2,
        ]);
        LeaveRequest::factory()->create([
            'driver_id' => $agent->id, 'driver_contract_id' => $contrat->id,
            'status' => 'completed', 'source' => 'driver_request',
            'start_date' => now()->subMonth()->toDateString(),
            'requested_days' => 3, 'effective_days' => 3,
        ]);

        [, $token] = $this->connecter(Profil::Admin, ['view-leaves']);

        $historique = collect(
            $this->entete($token)->getJson("/api/v1/admin/leaves/{$agent->id}")->assertOk()->json('history')
        )->keyBy('effective_days');

        $this->assertTrue($historique[2]['is_historical'], 'la saisie administrative n\'est pas reconnue');
        $this->assertFalse($historique[3]['is_historical'], 'une pause vécue est présentée comme corrigeable');
    }

    public function test_une_liste_vide_est_un_tableau_vide_et_non_une_erreur(): void
    {
        [, $token] = $this->connecter(Profil::Admin, ['view-leaves', 'view-leave-requests']);

        $this->entete($token)->getJson('/api/v1/admin/leaves')->assertOk()->assertExactJson([]);
        $this->entete($token)->getJson('/api/v1/admin/leave-requests')->assertOk()->assertExactJson([]);
    }
}
