<?php

namespace Tests\Feature\Workforce;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Kreait\Firebase\Contract\Messaging;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Les huit écritures de congés de l'administration.
 *
 * ⚠️ L'essentiel de ces tests porte sur les EFFETS DE BORD, pas sur le statut. Approuver
 * une pause crée une pause véhicule et rend l'agent indisponible ; la clôturer fait
 * l'inverse et recompte les jours ouvrés. Ce sont ces effets qui se perdent en
 * transposant, parce qu'ils ne se voient pas dans la réponse.
 */
class AdminLeaveWritesTest extends TestCase
{
    use RefreshDatabase;

    private const TOUTES = [
        'view-leaves', 'view-leave-requests', 'approve-leave-requests',
        'reject-leave-requests', 'create-leaves', 'edit-leaves', 'delete-leaves',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Firebase n'est jamais joint : seuls les effets en base nous intéressent.
        $this->app->instance(Messaging::class, Mockery::mock(Messaging::class)->shouldIgnoreMissing());
    }

    private function admin(array $permissions = self::TOUTES): string
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

    /** Voir NotificationsApiTest : le garde mémorise l'utilisateur entre deux requêtes. */
    private function entete(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /** Un agent, son contrat, et le véhicule qui va avec — la pause véhicule en dépend. */
    private function agent(): Driver
    {
        $user = User::factory()->profil(Profil::Driver)->create(['name' => 'Awa Dossou']);
        $driver = Driver::factory()->create(['user_id' => $user->id, 'is_available' => true]);

        $vehicule = Vehicle::factory()->create();
        $contratVehicule = VehicleContract::factory()->create(['vehicle_id' => $vehicule->id]);

        DriverContract::factory()->create([
            'driver_id' => $driver->id,
            'vehicle_id' => $vehicule->id,
            'vehicle_contract_id' => $contratVehicule->id,
            'start_date' => now()->subMonths(6)->startOfDay(),
            'contract_months' => 24,
            'status' => 'active',
        ]);

        return $driver->refresh();
    }

    private function demande(Driver $agent, string $debut = '+3 days'): LeaveRequest
    {
        return LeaveRequest::factory()->pending()->create([
            'driver_id' => $agent->id,
            'driver_contract_id' => $agent->activeDriverContract->id,
            'start_date' => now()->parse($debut)->toDateString(),
            'requested_days' => 3,
        ]);
    }

    // ----- Approuver ---------------------------------------------------------

    public function test_approuver_passe_la_demande_en_cours_et_immobilise_le_vehicule(): void
    {
        // ⚠️ Le statut `approved` n'existe plus depuis août : une demande acceptée passe
        // DIRECTEMENT à `ongoing`.
        $agent = $this->agent();
        $demande = $this->demande($agent, 'today');
        $token = $this->admin();

        $this->entete($token)
            ->postJson("/api/v1/admin/leave-requests/{$demande->id}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'ongoing');

        $this->assertNotNull($demande->fresh()->vehicle_pause_id, 'aucune pause véhicule créée');
        $this->assertDatabaseHas('vehicle_pauses', ['id' => $demande->fresh()->vehicle_pause_id]);
    }

    public function test_approuver_une_pause_qui_commence_aujourd_hui_rend_l_agent_indisponible(): void
    {
        $agent = $this->agent();
        $demande = $this->demande($agent, 'today');

        $this->entete($this->admin())->postJson("/api/v1/admin/leave-requests/{$demande->id}/approve")->assertOk();

        $this->assertFalse($agent->fresh()->is_available);
    }

    public function test_approuver_une_pause_a_venir_laisse_l_agent_disponible(): void
    {
        // Il roule jusqu'au jour dit : le rendre indisponible tout de suite lui ferait
        // perdre des courses sans raison.
        $agent = $this->agent();
        $demande = $this->demande($agent, '+10 days');

        $this->entete($this->admin())->postJson("/api/v1/admin/leave-requests/{$demande->id}/approve")->assertOk();

        $this->assertTrue($agent->fresh()->is_available);
    }

    public function test_approuver_previent_l_agent(): void
    {
        $agent = $this->agent();
        $demande = $this->demande($agent);

        $this->entete($this->admin())->postJson("/api/v1/admin/leave-requests/{$demande->id}/approve")->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $agent->user_id,
            'title' => 'Votre pause est validée',
        ]);
    }

    public function test_approuver_quand_une_pause_court_deja_est_refuse(): void
    {
        $agent = $this->agent();
        LeaveRequest::factory()->ongoing()->create([
            'driver_id' => $agent->id,
            'driver_contract_id' => $agent->activeDriverContract->id,
        ]);
        $demande = $this->demande($agent);

        $this->entete($this->admin())
            ->postJson("/api/v1/admin/leave-requests/{$demande->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('code', 'LEAVE_ALREADY_ONGOING');
    }

    // ----- Refuser -----------------------------------------------------------

    public function test_refuser_exige_un_motif_d_au_moins_cinq_caracteres(): void
    {
        // Le motif voyage jusqu'à l'agent : « non » n'explique rien.
        $demande = $this->demande($this->agent());
        $token = $this->admin();

        $this->entete($token)
            ->postJson("/api/v1/admin/leave-requests/{$demande->id}/reject", ['rejection_reason' => 'non'])
            ->assertStatus(422);

        $this->entete($token)
            ->postJson("/api/v1/admin/leave-requests/{$demande->id}/reject", [])
            ->assertStatus(422);
    }

    public function test_refuser_enregistre_le_motif_et_previent_l_agent(): void
    {
        $agent = $this->agent();
        $demande = $this->demande($agent);

        $this->entete($this->admin())
            ->postJson("/api/v1/admin/leave-requests/{$demande->id}/reject", [
                'rejection_reason' => 'Période trop chargée',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'rejected')
            ->assertJsonPath('rejection_reason', 'Période trop chargée');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $agent->user_id,
            'title' => 'Votre demande de pause est refusée',
        ]);
    }

    // ----- Clôturer ----------------------------------------------------------

    public function test_cloturer_recompte_les_jours_ouvres_reellement_pris(): void
    {
        // ⚠️ Et non les jours demandés : une pause écourtée n'a pas consommé ce qui avait
        // été demandé, et c'est ce décompte qui alimente le solde.
        $agent = $this->agent();
        $pause = LeaveRequest::factory()->ongoing()->create([
            'driver_id' => $agent->id,
            'driver_contract_id' => $agent->activeDriverContract->id,
            // Lundi 7 septembre 2026.
            'start_date' => '2026-09-07',
            'requested_days' => 10,
        ]);

        $this->entete($this->admin())
            ->patchJson("/api/v1/admin/leaves/{$pause->id}/end", ['end_date' => '2026-09-11'])
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            // Du lundi au vendredi : cinq jours ouvrés, pas dix.
            ->assertJsonPath('effective_days', 5);
    }

    public function test_cloturer_rend_l_agent_disponible_et_libere_le_vehicule(): void
    {
        $agent = $this->agent();
        $demande = $this->demande($agent, 'today');
        $token = $this->admin();

        $this->entete($token)->postJson("/api/v1/admin/leave-requests/{$demande->id}/approve")->assertOk();
        $this->assertFalse($agent->fresh()->is_available);

        $this->entete($token)
            ->patchJson("/api/v1/admin/leaves/{$demande->id}/end", ['end_date' => now()->toDateString()])
            ->assertOk();

        $this->assertTrue($agent->fresh()->is_available, "l'agent est resté indisponible");
        $this->assertNotNull(
            $demande->fresh()->vehiclePause?->end_date,
            'la pause véhicule est restée ouverte',
        );
    }

    public function test_une_fin_anterieure_au_debut_est_refusee(): void
    {
        $agent = $this->agent();
        $pause = LeaveRequest::factory()->ongoing()->create([
            'driver_id' => $agent->id,
            'driver_contract_id' => $agent->activeDriverContract->id,
            'start_date' => now()->toDateString(),
        ]);

        $this->entete($this->admin())
            ->patchJson("/api/v1/admin/leaves/{$pause->id}/end", ['end_date' => now()->subDays(5)->toDateString()])
            ->assertStatus(422)
            ->assertJsonPath('code', 'LEAVE_END_BEFORE_START');
    }

    public function test_cloturer_une_pause_deja_terminee_est_refuse(): void
    {
        $agent = $this->agent();
        $pause = LeaveRequest::factory()->create([
            'driver_id' => $agent->id,
            'driver_contract_id' => $agent->activeDriverContract->id,
        ]);

        $this->entete($this->admin())
            ->patchJson("/api/v1/admin/leaves/{$pause->id}/end", ['end_date' => now()->toDateString()])
            ->assertStatus(409)
            ->assertJsonPath('code', 'LEAVE_NOT_ONGOING');
    }

    // ----- Poser une pause ---------------------------------------------------

    public function test_poser_une_pause_en_cours_sans_demande_prealable(): void
    {
        $agent = $this->agent();

        $this->entete($this->admin())
            ->postJson("/api/v1/admin/drivers/{$agent->id}/leaves/ongoing", [
                'start_date' => now()->toDateString(),
                'requested_days' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'ongoing');

        $this->assertFalse($agent->fresh()->is_available);
        $this->assertDatabaseHas('leave_requests', ['driver_id' => $agent->id, 'source' => 'admin_instant']);
    }

    public function test_saisir_une_pause_historique_entierement_passee(): void
    {
        $agent = $this->agent();

        $this->entete($this->admin())
            ->postJson("/api/v1/admin/drivers/{$agent->id}/leaves/historical", [
                'start_date' => now()->subMonths(2)->toDateString(),
                'requested_days' => 3,
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('effective_days', 3);
    }

    public function test_une_pause_historique_qui_deborde_sur_aujourd_hui_est_refusee(): void
    {
        // ⚠️ Refus STRUCTUREL, pas cosmétique : une pause qui court encore devrait créer
        // une pause véhicule et rendre l'agent indisponible, ce que cette action ne fait
        // pas. La laisser passer laisserait le parc dans un état incohérent.
        $agent = $this->agent();

        $this->entete($this->admin())
            ->postJson("/api/v1/admin/drivers/{$agent->id}/leaves/historical", [
                'start_date' => now()->subDay()->toDateString(),
                'requested_days' => 10,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'LEAVE_NOT_ENTIRELY_PAST');
    }

    public function test_un_agent_sans_contrat_actif_ne_fait_plus_tomber_la_requete(): void
    {
        // ⚠️ Le contrôleur Blade lisait `$contract->start_date` sans vérifier que le
        // contrat existe : sur un agent sans contrat, la requête mourait en 500 sans
        // message. Corrigé plutôt que transposé.
        $user = User::factory()->profil(Profil::Driver)->create();
        $agent = Driver::factory()->create(['user_id' => $user->id]);

        $this->entete($this->admin())
            ->postJson("/api/v1/admin/drivers/{$agent->id}/leaves/historical", [
                'start_date' => now()->subMonths(2)->toDateString(),
                'requested_days' => 2,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'LEAVE_NO_ACTIVE_CONTRACT');
    }

    // ----- Corriger et supprimer ---------------------------------------------

    public function test_seule_une_pause_d_origine_administrative_se_corrige(): void
    {
        // ⚠️ Une pause terminée issue d'une demande d'agent a été VÉCUE : la réécrire
        // changerait un fait, pas une saisie.
        $agent = $this->agent();
        $vecue = LeaveRequest::factory()->create([
            'driver_id' => $agent->id,
            'driver_contract_id' => $agent->activeDriverContract->id,
            'source' => 'driver_request',
        ]);

        $this->entete($this->admin())
            ->patchJson("/api/v1/admin/leaves/{$vecue->id}/historical", [
                'start_date' => now()->subMonths(3)->toDateString(),
                'requested_days' => 2,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'LEAVE_NOT_HISTORICAL');
    }

    public function test_corriger_une_pause_historique_remplace_le_decompte_au_lieu_de_s_y_ajouter(): void
    {
        // ⚠️ Sans le retrait de l'ancien décompte, une correction s'AJOUTE à la saisie
        // d'origine : cinq jours corrigés en deux en feraient sept.
        $agent = $this->agent();
        $pause = LeaveRequest::factory()->create([
            'driver_id' => $agent->id,
            'driver_contract_id' => $agent->activeDriverContract->id,
            'source' => 'admin_historical',
            'start_date' => now()->subMonths(3)->toDateString(),
            'requested_days' => 5,
            'effective_days' => 5,
        ]);
        // ⚠️ L'état que la saisie d'origine aurait laissé. Sans lui la colonne part de
        // zéro, `markLeaveDaysUsed` la borne à zéro par son `max(0, …)`, et le test
        // passe avec ou sans le retrait — donc sans rien prouver.
        $agent->update(['leave_days_used' => 5]);

        $this->entete($this->admin())
            ->patchJson("/api/v1/admin/leaves/{$pause->id}/historical", [
                'start_date' => now()->subMonths(3)->toDateString(),
                'requested_days' => 2,
            ])
            ->assertOk()
            ->assertJsonPath('effective_days', 2);

        // ⚠️ On vérifie la COLONNE `leave_days_used`, et non `getLeaveDaysTaken()`.
        // Ce dernier RECOMPTE depuis les pauses terminées : il vaudrait 2 même si
        // l'ancien décompte n'avait pas été retiré, et le test passerait pour la
        // mauvaise raison. C'est la colonne que l'action écrit, donc elle qu'on éprouve.
        $this->assertSame(2, (int) $agent->fresh()->leave_days_used, 'le décompte s\'est ajouté au lieu de remplacer');
        $this->assertSame(2, $agent->fresh()->getLeaveDaysTaken());
    }

    public function test_supprimer_une_pause_historique_la_retire_du_solde(): void
    {
        $agent = $this->agent();
        $pause = LeaveRequest::factory()->create([
            'driver_id' => $agent->id,
            'driver_contract_id' => $agent->activeDriverContract->id,
            'source' => 'admin_historical',
            'requested_days' => 4,
            'effective_days' => 4,
        ]);
        // Même raison : la colonne doit porter le décompte que la suppression doit retirer.
        $agent->update(['leave_days_used' => 4]);

        $this->entete($this->admin())
            ->deleteJson("/api/v1/admin/leaves/{$pause->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('leave_requests', ['id' => $pause->id]);
        $this->assertSame(0, (int) $agent->fresh()->leave_days_used, 'le décompte n\'a pas été retiré');
        $this->assertSame(0, $agent->fresh()->getLeaveDaysTaken());
    }

    public function test_corriger_une_pause_en_cours_repercute_sur_la_pause_vehicule(): void
    {
        // ⚠️ Le défaut le plus coûteux de cet écran : le propriétaire verrait son
        // tricycle immobilisé à une date et l'agent en pause à une autre.
        $agent = $this->agent();
        $demande = $this->demande($agent, 'today');
        $token = $this->admin();

        $this->entete($token)->postJson("/api/v1/admin/leave-requests/{$demande->id}/approve")->assertOk();

        $nouvelleDate = now()->addDays(5)->toDateString();
        $this->entete($token)
            ->patchJson("/api/v1/admin/leaves/{$demande->id}/ongoing", [
                'start_date' => $nouvelleDate,
                'requested_days' => 4,
            ])
            ->assertOk();

        $this->assertSame(
            $nouvelleDate,
            $demande->fresh()->vehiclePause?->start_date?->toDateString(),
            'la pause véhicule est restée à son ancienne date',
        );
    }

    // ----- Les gardes --------------------------------------------------------

    public function test_chaque_ecriture_exige_sa_propre_permission(): void
    {
        // ⚠️ Six de ces routes n'ont aucune garde côté Blade : l'API est plus stricte.
        $agent = $this->agent();
        $demande = $this->demande($agent);
        // Un compte qui peut tout LIRE et rien écrire.
        $token = $this->admin(['view-leaves', 'view-leave-requests']);

        $this->entete($token)->postJson("/api/v1/admin/leave-requests/{$demande->id}/approve")->assertForbidden();
        $this->entete($token)->postJson("/api/v1/admin/leave-requests/{$demande->id}/reject", ['rejection_reason' => 'Période chargée'])->assertForbidden();
        $this->entete($token)->patchJson("/api/v1/admin/leaves/{$demande->id}/end", ['end_date' => now()->toDateString()])->assertForbidden();
        $this->entete($token)->postJson("/api/v1/admin/drivers/{$agent->id}/leaves/ongoing", ['start_date' => now()->toDateString(), 'requested_days' => 1])->assertForbidden();
        $this->entete($token)->deleteJson("/api/v1/admin/leaves/{$demande->id}")->assertForbidden();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
