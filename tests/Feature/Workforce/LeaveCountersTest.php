<?php

namespace Tests\Feature\Workforce;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Domains\Workforce\Application\Actions\ListDriverLeaves;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les cinq compteurs de l'écran des pauses.
 *
 * Point de départ : un agent réel affichait, le 2026-09-21,
 * `jours par mois 2 / total 48 / utilisés 0 / disponibles -5 / restants 48`
 * alors que son historique montrait cinq jours effectivement pris. Trois de ces
 * nombres se contredisent, et ce fichier commence par REPRODUIRE la situation exacte
 * avant de corriger quoi que ce soit — c'est la seule façon de savoir qu'on corrige la
 * bonne chose.
 */
class LeaveCountersTest extends TestCase
{
    use RefreshDatabase;

    /** Un agent et ses pauses terminées, avec ou sans contrat actif. */
    private function agent(bool $avecContrat, int $joursTermines = 0, int $moisEcoules = 6): Driver
    {
        $user = User::factory()->profil(Profil::Driver)->create();
        $driver = Driver::factory()->create(['user_id' => $user->id, 'leave_days_used' => 0]);

        $contrat = null;

        if ($avecContrat) {
            $contrat = DriverContract::factory()->create([
                'driver_id' => $driver->id,
                'start_date' => now()->subMonths($moisEcoules)->startOfDay(),
                'contract_months' => 24,
                'status' => 'active',
            ]);
        }

        if ($joursTermines > 0) {
            LeaveRequest::factory()->create([
                'driver_id' => $driver->id,
                'driver_contract_id' => $contrat?->id,
                'status' => 'completed',
                'start_date' => now()->subMonths(2)->toDateString(),
                'end_date' => now()->subMonths(2)->addDays($joursTermines - 1)->toDateString(),
                'requested_days' => $joursTermines,
                'effective_days' => $joursTermines,
            ]);
        }

        return $driver->refresh();
    }

    /** @return array<string, mixed> */
    private function compteurs(Driver $driver): array
    {
        return app(ListDriverLeaves::class)($driver)['solde'];
    }

    public function test_un_agent_sans_contrat_actif_ne_se_voit_promettre_aucun_droit(): void
    {
        // ⚠️ LE défaut. `getContractMonths()` retombait sur « 24 mois » par défaut quand
        // aucun contrat n'était actif, donc l'écran annonçait 48 jours de congés à
        // quelqu'un qui n'a aucun contrat — un chiffre inventé, présenté comme un fait.
        $driver = $this->agent(avecContrat: false, joursTermines: 5);

        $compteurs = $this->compteurs($driver);

        $this->assertSame(0, $compteurs['total_leave_days'], 'un droit annoncé sans contrat');
        $this->assertSame(0, $compteurs['remaining_leave_days']);
    }

    public function test_les_jours_utilises_comptent_les_pauses_reellement_prises(): void
    {
        // ⚠️ Le second défaut, et le plus visible : « Jours utilisés : 0 » s'affichait
        // au-dessus d'un historique listant cinq jours. Deux nombres qui se contredisent
        // sur le même écran, parce que `leave_days_used` est une COLONNE mise à jour à la
        // clôture d'une pause, et qu'elle ne l'avait jamais été.
        $driver = $this->agent(avecContrat: true, joursTermines: 5);

        $this->assertSame(5, $this->compteurs($driver)['leave_days_used']);
    }

    public function test_les_jours_restants_tiennent_compte_des_pauses_prises(): void
    {
        $driver = $this->agent(avecContrat: true, joursTermines: 5);

        $compteurs = $this->compteurs($driver);

        $this->assertSame(48, $compteurs['total_leave_days']);
        $this->assertSame(43, $compteurs['remaining_leave_days'], '48 - 5');
    }

    public function test_les_disponibles_a_date_suivent_l_acquisition_mensuelle(): void
    {
        // ⚠️ Le MOIS EN COURS est crédité : `getContractMonthsElapsed()` fait
        // `diffInMonths + 1`. Six mois révolus comptent donc pour sept, soit quatorze
        // jours acquis. Ce n'est pas un écart, c'est la règle du projet — un agent
        // acquiert ses deux jours à l'entrée dans le mois, pas à sa sortie.
        $driver = $this->agent(avecContrat: true, joursTermines: 5, moisEcoules: 6);

        $this->assertSame(9, $this->compteurs($driver)['available_leave_days'], '14 acquis - 5 pris');
    }

    public function test_les_disponibles_a_date_peuvent_etre_negatifs_et_c_est_normal(): void
    {
        // ⚠️ Ceci n'est PAS un défaut et ne doit pas être « corrigé » à zéro. Le projet
        // n'interdit pas le dépassement : un agent peut prendre d'avance sur son
        // acquisition. Le négatif dit « vous êtes en avance sur vos droits », ce qui est
        // une information, pas une erreur de calcul.
        $driver = $this->agent(avecContrat: true, joursTermines: 5, moisEcoules: 1);

        $this->assertSame(-1, $this->compteurs($driver)['available_leave_days'], '4 acquis - 5 pris');
    }

    public function test_les_pauses_d_un_contrat_precedent_ne_grevent_pas_le_nouveau(): void
    {
        // ⚠️ LE cas de l'agent réel, reproduit. Cinq jours pris sous un contrat clos, puis
        // un nouveau contrat : le solde doit repartir entier. C'est déjà ce que fait
        // `DriverContractService`, qui remet `leave_days_used` à zéro à la clôture — le
        // recalcul devait suivre la même règle, sans quoi il aurait rouvert le défaut
        // qu'il venait de corriger.
        $user = User::factory()->profil(Profil::Driver)->create();
        $driver = Driver::factory()->create(['user_id' => $user->id, 'leave_days_used' => 0]);

        $ancien = DriverContract::factory()->create([
            'driver_id' => $driver->id,
            'start_date' => now()->subMonths(30)->startOfDay(),
            'contract_months' => 24,
            'status' => 'completed',
        ]);
        LeaveRequest::factory()->create([
            'driver_id' => $driver->id,
            'driver_contract_id' => $ancien->id,
            'status' => 'completed',
            'start_date' => now()->subMonths(20)->toDateString(),
            'requested_days' => 5,
            'effective_days' => 5,
        ]);

        DriverContract::factory()->create([
            'driver_id' => $driver->id,
            'start_date' => now()->subMonths(2)->startOfDay(),
            'contract_months' => 24,
            'status' => 'active',
        ]);

        $compteurs = $this->compteurs($driver->refresh());

        $this->assertSame(0, $compteurs['leave_days_used'], 'les pauses du contrat clos sont retombées dans le nouveau');
        $this->assertSame(48, $compteurs['remaining_leave_days']);
        // Trois mois crédités (deux révolus + le mois en cours), rien de pris.
        $this->assertSame(6, $compteurs['available_leave_days']);
    }

    public function test_les_compteurs_ne_se_contredisent_jamais_entre_eux(): void
    {
        // Le garde-fou global : quelle que soit la situation, « restants » doit valoir
        // « total moins utilisés ». C'est l'invariant que l'écran réel violait.
        foreach ([[true, 5], [true, 0], [false, 5], [false, 0]] as [$contrat, $jours]) {
            $c = $this->compteurs($this->agent(avecContrat: $contrat, joursTermines: $jours));

            $this->assertSame(
                $c['total_leave_days'] - $c['leave_days_used'],
                $c['remaining_leave_days'],
                'contrat='.var_export($contrat, true).' jours='.$jours,
            );
        }
    }
}
