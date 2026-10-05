<?php

namespace Tests\Feature\Audit;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Audit\Domain\ActivityEvent;
use App\Domains\Finance\Application\Actions\GenerateDailyContractPayments;
use App\Domains\Finance\Application\Actions\GenerateRemunerationStatements;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Le journal d'activité, lot 2 : la flotte et les paiements (2026-09-29).
 */
class ActivityJournalFleetFinanceTest extends TestCase
{
    use RefreshDatabase;

    private function token(array $permissions, string $name = 'Awa Dossou'): string
    {
        $user = User::factory()->profil(Profil::Admin)->create(['name' => $name, 'password' => Hash::make('bon-mot-de-passe')]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        Auth::forgetGuards();

        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])->json('token');
    }

    private function as(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /** Un propriétaire tel que `FindOwner` le reconnaît : profil ET rôle `proprietaire`. */
    private function owner(string $name): User
    {
        $owner = User::factory()->profil(Profil::Owner)->create(['name' => $name]);
        $owner->assignRole(Role::findOrCreate('proprietaire', 'web'));

        return $owner;
    }

    private function last(string $event): Activity
    {
        return Activity::query()->where('event', $event)->latest('id')->firstOrFail();
    }

    private function contractedDriver(): DriverContract
    {
        $vehicle = Vehicle::factory()->create();
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create();
        $user = User::factory()->profil(Profil::Driver)->create(['name' => 'Koffi Mensah']);
        $driver = Driver::factory()->create(['user_id' => $user->id]);

        return DriverContract::factory()->forVehicleContract($contract)->create(['driver_id' => $driver->id]);
    }

    // ----- Comptes -----------------------------------------------------------------------

    public function test_desactiver_un_proprietaire_est_trace_avec_son_nom(): void
    {
        $owner = $this->owner('Rachid Bello');

        $this->as($this->token(['edit-owners']))
            ->postJson("/api/v1/admin/owners/{$owner->id}/toggle-status", ['is_active' => false])
            ->assertOk();

        $entry = $this->last('account.status_changed');
        $this->assertSame('a désactivé le compte propriétaire de Rachid Bello', $entry->description);
        $this->assertSame('Awa Dossou', $entry->properties['actor']);
        $this->assertSame('user', $entry->subject_type);
    }

    public function test_supprimer_un_proprietaire_garde_son_nom(): void
    {
        $owner = $this->owner('Rachid Bello');

        $this->as($this->token(['delete-owners']))
            ->deleteJson("/api/v1/admin/owners/{$owner->id}")
            ->assertNoContent();

        $this->assertSame('a supprimé le compte propriétaire de Rachid Bello', $this->last('account.deleted')->description);
    }

    // ----- Flotte ------------------------------------------------------------------------

    public function test_ajouter_un_vehicule_est_trace(): void
    {
        $this->as($this->token(['create-vehicles']))
            ->postJson('/api/v1/admin/vehicles', ['vehicle_number' => 'BJ-4521-AB', 'vehicle_type' => 'tricycle'])
            ->assertCreated();

        $this->assertSame('a ajouté le véhicule BJ-4521-AB', $this->last('vehicle.created')->description);
    }

    public function test_une_mise_en_pause_dit_son_motif(): void
    {
        $vehicle = Vehicle::factory()->create(['vehicle_number' => 'BJ-0007-CC', 'is_active' => true]);
        VehicleContract::factory()->forVehicle($vehicle)->create();

        $this->as($this->token(['manage-vehicle-pauses']))
            ->postJson("/api/v1/admin/vehicles/{$vehicle->id}/pauses", [
                'start_date' => now()->toDateString(),
                'reason_type' => 'accident',
            ])
            ->assertCreated();

        $entry = $this->last('vehicle.paused');
        $this->assertSame('a mis en pause le véhicule BJ-0007-CC (Accident)', $entry->description);
        $this->assertSame($vehicle->id, $entry->subject_id);
    }

    // ----- Paiements ---------------------------------------------------------------------

    public function test_valider_un_paiement_dit_le_montant_et_l_agent(): void
    {
        $contract = $this->contractedDriver();
        $payment = Payment::factory()->status('pending')->create([
            'driver_id' => $contract->driver_id,
            'driver_contract_id' => $contract->id,
            'vehicle_contract_id' => $contract->vehicle_contract_id,
            'payment_type' => 'contract',
            'amount' => 6112,
            'net_amount' => 6112 - 241,
        ]);

        $this->as($this->token(['edit-payments']))
            ->postJson("/api/v1/admin/payments/{$payment->id}/validate")
            ->assertOk();

        $entry = $this->last('payment.validated');
        $this->assertSame('a validé le paiement de 6 112 FCFA de Koffi Mensah', $entry->description);
        $this->assertSame('payment', $entry->subject_type);
    }

    public function test_la_generation_du_soir_ecrit_une_seule_ligne_au_nom_du_systeme(): void
    {
        // Une ligne par paiement noierait le journal : une par contrat et par jour ouvré.
        $this->contractedDriver();
        $this->contractedDriver();

        app(GenerateDailyContractPayments::class)(Carbon::parse('2026-09-28'));

        $entries = Activity::query()->where('event', 'payment.daily_generated')->get();
        $this->assertCount(1, $entries);
        $this->assertSame('a généré 2 paiements journaliers', $entries->first()->description);
        $this->assertSame('Système', $entries->first()->properties['actor']);
    }

    public function test_un_week_end_sans_paiement_n_ecrit_rien(): void
    {
        $this->contractedDriver();

        app(GenerateDailyContractPayments::class)(Carbon::parse('2026-09-27'));

        $this->assertSame(0, Activity::query()->where('event', 'payment.daily_generated')->count());
    }

    public function test_le_filtre_flotte_attrape_aussi_les_contrats(): void
    {
        // Un groupe = un préfixe : les contrats sont `vehicle.contract_*`.
        $token = $this->token(['create-contracts', 'view-activity-log']);
        $vehicle = Vehicle::factory()->create();

        $this->as($token)->postJson('/api/v1/admin/vehicle-contracts', [
            'vehicle_id' => $vehicle->id,
            'contract_months' => 24,
            'total_amount' => 3_100_000,
            'start_date' => '2026-10-01',
        ])->assertCreated();

        $events = array_column(
            $this->as($token)->getJson('/api/v1/admin/activity-log?filter[event]=vehicle.')->assertOk()->json('entries'),
            'event',
        );
        $this->assertSame(['vehicle.contract_created'], $events);
    }

    // ----- Fiches de rémunération --------------------------------------------------------

    private function statement(): RemunerationStatement
    {
        $vehicle = Vehicle::factory()->create(['owner_id' => $this->owner('Eude Assogba')->id]);
        $contract = VehicleContract::factory()->forVehicle($vehicle)->create();

        return RemunerationStatement::factory()->for($contract, 'contract')->validated()
            ->create(['month' => '2026-10-01', 'number' => 'FR-2026-10-001']);
    }

    public function test_valider_une_fiche_de_remuneration_dit_son_numero_et_le_proprietaire(): void
    {
        app(ActivityJournal::class)->remunerationStatementValidated($this->statement());

        $entry = $this->last('remuneration_statement.validated');
        $this->assertSame('a validé la fiche de rémunération FR-2026-10-001 de Eude Assogba', $entry->description);
        $this->assertSame('remuneration_statement', $entry->subject_type);
    }

    public function test_l_envoi_d_une_fiche_est_au_nom_du_systeme(): void
    {
        app(ActivityJournal::class)->remunerationStatementSent($this->statement());

        $entry = $this->last('remuneration_statement.sent');
        $this->assertSame('a envoyé la fiche de rémunération FR-2026-10-001 à Eude Assogba', $entry->description);
        $this->assertSame('remuneration_statement', $entry->subject_type);
        $this->assertSame('Système', $entry->properties['actor']);
    }

    public function test_l_annulation_d_une_fiche_dit_son_motif(): void
    {
        app(ActivityJournal::class)->remunerationStatementCancelled($this->statement(), 'Erreur sur les charges');

        $entry = $this->last('remuneration_statement.cancelled');
        $this->assertSame('a annulé la fiche de rémunération FR-2026-10-001 de Eude Assogba : Erreur sur les charges', $entry->description);
        $this->assertSame('remuneration_statement', $entry->subject_type);
    }

    public function test_la_generation_des_brouillons_ecrit_une_ligne_au_nom_du_systeme(): void
    {
        Carbon::setTestNow('2026-11-01 02:00:00');
        config(['remuneration.first_month' => '2026-10']);
        VehicleContract::factory()->count(3)->create(['start_date' => '2026-05-01']);

        app(GenerateRemunerationStatements::class)('2026-10');
        app(GenerateRemunerationStatements::class)('2026-10');
        Carbon::setTestNow();

        // La seconde passe ne crée rien : elle n'écrit rien.
        $entries = Activity::query()->where('event', 'remuneration_statement.generated')->get();
        $this->assertCount(1, $entries);
        $this->assertSame('a créé 3 brouillon(s) de fiches de rémunération pour octobre 2026', $entries->first()->description);
        $this->assertNull($entries->first()->subject_type);
        $this->assertSame('Système', $entries->first()->properties['actor']);
    }

    public function test_les_fiches_forment_leur_propre_groupe(): void
    {
        $this->assertSame('Fiches de rémunération', ActivityEvent::RemunerationStatementValidated->group());
        $this->assertSame('Fiche de rémunération validée', ActivityEvent::RemunerationStatementValidated->label());
    }

    /** « Contrat propriétaire » remplace « Contrat véhicule » dans l'administration (2026-10-05). */
    public function test_les_contrats_s_appellent_contrats_proprietaires(): void
    {
        $this->assertSame('Contrat propriétaire créé', ActivityEvent::VehicleContractCreated->label());
        $this->assertSame('Contrat propriétaire modifié', ActivityEvent::VehicleContractUpdated->label());
        $this->assertSame('Contrat propriétaire supprimé', ActivityEvent::VehicleContractDeleted->label());
    }
}
