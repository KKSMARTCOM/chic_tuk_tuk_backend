<?php

namespace Tests\Feature\Workforce;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Domains\Workforce\Application\Actions\ListDriversForLeaves;
use App\Domains\Workforce\Application\Data\AdminDriverLeaveSummaryData;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La liste des congés de l'administration.
 *
 * ⚠️ Elle montre AUSSI les anciens agents, ceux qui ne sont pas allés au bout de leur
 * contrat. Le contrôleur Blade ne listait que les agents sous contrat actif, et leur
 * dossier devenait donc inconsultable dès leur départ. Demandé le 2026-09-22.
 *
 * Le point délicat n'est pas le filtre mais le CALCUL : rapporter le solde d'un ancien
 * agent à son contrat actif — qui n'existe plus — n'afficherait que des zéros. Il est
 * rapporté à son dernier contrat.
 */
class AdminLeavesListTest extends TestCase
{
    use RefreshDatabase;

    private function agent(string $nom, ?string $statutContrat, int $joursPris = 0, int $moisEcoules = 6): Driver
    {
        $user = User::factory()->profil(Profil::Driver)->create(['name' => $nom]);
        $driver = Driver::factory()->create(['user_id' => $user->id, 'leave_days_used' => 0]);

        if ($statutContrat === null) {
            return $driver->refresh();
        }

        $contrat = DriverContract::factory()->create([
            'driver_id' => $driver->id,
            'start_date' => now()->subMonths($moisEcoules)->startOfDay(),
            'end_date' => $statutContrat === 'active' ? null : now()->subDays(10)->toDateString(),
            'contract_months' => 24,
            'status' => $statutContrat,
        ]);

        if ($joursPris > 0) {
            LeaveRequest::factory()->create([
                'driver_id' => $driver->id,
                'driver_contract_id' => $contrat->id,
                'status' => 'completed',
                'start_date' => now()->subMonths(2)->toDateString(),
                'requested_days' => $joursPris,
                'effective_days' => $joursPris,
            ]);
        }

        return $driver->refresh();
    }

    /** @return array<int, array<string, mixed>> */
    private function lister(array $filtres = []): array
    {
        return app(ListDriversForLeaves::class)($filtres)
            ->map(fn (Driver $d) => AdminDriverLeaveSummaryData::fromModel($d)->toArray())
            ->all();
    }

    public function test_un_ancien_agent_reste_dans_la_liste(): void
    {
        $this->agent('Sous contrat', 'active');
        $this->agent('Parti avant le terme', 'completed');

        $noms = array_column($this->lister(), 'name');

        $this->assertContains('Parti avant le terme', $noms, 'l\'ancien agent a disparu de la liste');
        $this->assertContains('Sous contrat', $noms);
    }

    public function test_le_dossier_d_un_ancien_agent_reste_lisible(): void
    {
        // ⚠️ Le vrai enjeu. Le rapporter à son contrat ACTIF — qui n'existe plus —
        // n'afficherait que des zéros, et la ligne ne dirait rien de ce qu'il a pris.
        $this->agent('Ancien', 'completed', joursPris: 5);

        $ligne = $this->lister()[0];

        $this->assertSame(48, $ligne['total_leave_days'], 'le droit de son dernier contrat a disparu');
        $this->assertSame(5, $ligne['leave_days_used'], 'ses cinq jours pris ont disparu');
        $this->assertSame(43, $ligne['remaining_leave_days']);
    }

    public function test_un_ancien_agent_est_signale_comme_tel(): void
    {
        // Sans ce drapeau, rien ne le distingue à l'écran et on lit son solde comme un
        // droit en cours.
        $this->agent('Sous contrat', 'active');
        $this->agent('Ancien', 'completed');

        $lignes = collect($this->lister())->keyBy('name');

        $this->assertTrue($lignes['Sous contrat']['has_active_contract']);
        $this->assertFalse($lignes['Ancien']['has_active_contract']);
    }

    public function test_un_ancien_agent_n_acquiert_plus_de_jours_apres_son_depart(): void
    {
        // ⚠️ L'acquisition s'arrête à la date de FIN du contrat. Sans cette borne, un
        // agent parti il y a deux ans continuerait d'accumuler ses deux jours par mois.
        $user = User::factory()->profil(Profil::Driver)->create(['name' => 'Parti il y a longtemps']);
        $driver = Driver::factory()->create(['user_id' => $user->id]);
        DriverContract::factory()->create([
            'driver_id' => $driver->id,
            'start_date' => now()->subMonths(30)->startOfDay(),
            'end_date' => now()->subMonths(24)->toDateString(),
            'contract_months' => 24,
            'status' => 'completed',
        ]);

        $ligne = $this->lister()[0];

        // Sept mois courus entre le début et la fin (six révolus + le mois en cours).
        $this->assertSame(14, $ligne['available_leave_days'], 'l\'acquisition a continué après le départ');
    }

    public function test_un_agent_sans_aucun_contrat_reste_ecarte(): void
    {
        // Il n'a pas de dossier de congés, seulement une fiche d'agent : l'afficher
        // n'ajouterait qu'une ligne de zéros.
        $this->agent('Jamais sous contrat', null);

        $this->assertSame([], $this->lister());
    }

    public function test_le_filtre_de_statut_separe_les_deux_populations(): void
    {
        $this->agent('Sous contrat', 'active');
        $this->agent('Ancien', 'completed');

        $this->assertSame(['Sous contrat'], array_column($this->lister(['status' => 'active']), 'name'));
        $this->assertSame(['Ancien'], array_column($this->lister(['status' => 'former']), 'name'));
    }

    public function test_la_recherche_porte_sur_le_nom_de_l_agent(): void
    {
        $this->agent('Awa Dossou', 'active');
        $this->agent('Kofi Mensah', 'active');

        $this->assertSame(['Awa Dossou'], array_column($this->lister(['search' => 'Awa']), 'name'));
    }

    public function test_le_filtre_des_disponibles_porte_sur_la_valeur_calculee(): void
    {
        // ⚠️ `available_leave_days` n'est dans aucune colonne : il croise l'acquisition
        // mensuelle et trois statuts de pause. Le filtre s'applique donc APRÈS la requête.
        $this->agent('A du solde', 'active', joursPris: 1, moisEcoules: 6);
        $this->agent('En dépassement', 'active', joursPris: 20, moisEcoules: 1);

        $this->assertSame(['A du solde'], array_column($this->lister(['available' => 'yes']), 'name'));
        $this->assertSame(['En dépassement'], array_column($this->lister(['available' => 'no']), 'name'));
    }

    public function test_le_filtre_des_demandes_en_attente(): void
    {
        $avecDemande = $this->agent('Avec demande', 'active');
        $this->agent('Sans demande', 'active');

        LeaveRequest::factory()->pending()->create([
            'driver_id' => $avecDemande->id,
            'driver_contract_id' => $avecDemande->activeDriverContract->id,
            'requested_days' => 2,
        ]);

        $this->assertSame(['Avec demande'], array_column($this->lister(['pending' => 'yes']), 'name'));
        $this->assertSame(['Sans demande'], array_column($this->lister(['pending' => 'no']), 'name'));
    }

    public function test_le_filtre_de_duree_de_contrat_porte_sur_la_valeur_affichee(): void
    {
        // ⚠️ CORRIGÉ le 2026-09-22. Le contrôleur Blade filtrait sur
        // `drivers.contract_type`, une colonne héritée que plus rien n'écrit et qui vaut
        // NULL sur toute la flotte, alors que la colonne « Durée contrat » affiche
        // `contract_months` du CONTRAT. Choisir une durée ne rendait donc jamais rien.
        $user = User::factory()->profil(Profil::Driver)->create(['name' => 'Douze mois']);
        $court = Driver::factory()->create(['user_id' => $user->id]);
        DriverContract::factory()->create([
            'driver_id' => $court->id,
            'start_date' => now()->subMonths(3)->startOfDay(),
            'end_date' => null,
            'contract_months' => 12,
            'status' => 'active',
        ]);

        $this->agent('Vingt-quatre mois', 'active');

        $this->assertSame(['Douze mois'], array_column($this->lister(['contract' => 12]), 'name'));
        $this->assertSame(['Vingt-quatre mois'], array_column($this->lister(['contract' => 24]), 'name'));
    }

    public function test_une_pause_en_cours_est_signalee_avec_sa_date(): void
    {
        $driver = $this->agent('En pause', 'active');
        LeaveRequest::factory()->ongoing()->create([
            'driver_id' => $driver->id,
            'driver_contract_id' => $driver->activeDriverContract->id,
            'start_date' => '2026-09-15',
            'requested_days' => 3,
        ]);

        $ligne = $this->lister()[0];

        $this->assertTrue($ligne['is_on_leave']);
        $this->assertSame('2026-09-15', $ligne['ongoing_since']);
    }
}
