<?php

namespace Tests\Feature\Booking;

use App\Domains\Booking\Application\Actions\ListAvailableBookings;
use App\Models\Booking;
use App\Models\Driver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le test différentiel : dix formes × trois observateurs.
 *
 * L'ancienne implémentation (BookingService::getAvailableBookings) et la nouvelle
 * (ListAvailableBookings) devaient rendre EXACTEMENT les mêmes identifiants, dans le même
 * ordre. L'ancienne a disparu avec le Blade le 2026-09-27 : ce qu'elle rendait sur ces
 * dix formes a été relevé en la faisant tourner, et figé ici forme par forme.
 */
class AvailableBookingsDifferentialTest extends TestCase
{
    use RefreshDatabase;

    private Driver $titulaire;

    private Driver $autre;

    private Driver $tiers;

    /** @var array<string, string> nom de la forme => identifiant de la course */
    private array $formes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->titulaire = Driver::factory()->create();
        $this->autre = Driver::factory()->create();
        $this->tiers = Driver::factory()->create();

        $this->creerLesDixFormes();
    }

    /**
     * Les dix formes, créées une seule fois et partagées.
     *
     * Les heures de prise en charge sont TOUTES distinctes et croissantes dans l'ordre
     * de création : l'ordre attendu est ainsi déterministe, et une comparaison de listes
     * ordonnées a un sens. Deux courses à la même heure rendraient le test capricieux
     * sans le rendre faux, ce qui est pire.
     */
    private function creerLesDixFormes(): void
    {
        $heure = fn (int $n) => sprintf('%02d:00', 6 + $n);

        // 1. Course unique aller simple, sans aller-retour → visible de tous.
        $this->formes['unique_simple'] = Booking::factory()
            ->create(['pickup_time' => $heure(1)])->id;

        // 2. Course unique aller AVEC aller-retour → visible de tous.
        $allerRetour = Booking::factory()->roundTrip('20:00')
            ->create(['pickup_time' => $heure(2)]);
        $this->formes['unique_aller_retour'] = $allerRetour->id;

        // 3. Course retour cachée AVANT acceptation → visible de personne.
        $this->formes['retour_cachee_libre'] = Booking::factory()
            ->returnOf($allerRetour)
            ->create(['pickup_time' => $heure(3)])->id;

        // 4. Course retour cachée APRÈS acceptation par le titulaire → lui seul.
        $allerPris = Booking::factory()->roundTrip('21:00')
            ->create(['pickup_time' => $heure(4), 'status' => 'confirmed', 'driver_id' => $this->titulaire->id]);
        $this->formes['retour_cachee_prise'] = Booking::factory()
            ->returnOf($allerPris)
            ->linkedToSubscriptionDriver($this->titulaire)
            ->create(['pickup_time' => $heure(5)])->id;

        // 5. Abonnement parent sans titulaire → visible de tous.
        $this->formes['abo_parent_libre'] = Booking::factory()
            ->subscriptionParent()
            ->create(['pickup_time' => $heure(6)])->id;

        // 6. Abonnement parent lié au titulaire → lui seul.
        $aboLie = Booking::factory()
            ->subscriptionParent()
            ->linkedToSubscriptionDriver($this->titulaire)
            ->create(['pickup_time' => $heure(7)]);
        $this->formes['abo_parent_lie'] = $aboLie->id;

        // 7. Enfant d'abonnement lié au titulaire → lui seul.
        $this->formes['abo_enfant_lie'] = Booking::factory()
            ->subscriptionChild($aboLie)
            ->linkedToSubscriptionDriver($this->titulaire)
            ->create(['pickup_time' => $heure(8)])->id;

        // 8. Enfant d'abonnement révoqué → visible de tous.
        $this->formes['abo_enfant_revoque'] = Booking::factory()
            ->subscriptionChild($aboLie)
            ->revoked()
            ->create(['pickup_time' => $heure(9)])->id;

        // 9. Course retour d'abonnement liée au titulaire → lui seul.
        $this->formes['abo_retour_lie'] = Booking::factory()
            ->returnOf($aboLie)
            ->linkedToSubscriptionDriver($this->titulaire)
            ->create(['pickup_time' => $heure(10)])->id;

        // 10. Course retour révoquée → visible de tous.
        $this->formes['abo_retour_revoque'] = Booking::factory()
            ->returnOf($aboLie)
            ->revoked()
            ->create(['pickup_time' => $heure(11)])->id;
    }

    /**
     * Ce que rendait l'ancienne implémentation, relevé le 2026-09-27 avant sa suppression.
     *
     * @param  list<string>  $forms
     * @return array<int, string>
     */
    private function ancienne(array $forms): array
    {
        return array_map(fn (string $form) => $this->formes[$form], $forms);
    }

    /** @return array<int, string> */
    private function nouvelle(Driver $driver): array
    {
        return app(ListAvailableBookings::class)($driver->id)->pluck('id')->all();
    }

    public function test_le_titulaire_voit_la_meme_chose_des_deux_implementations(): void
    {
        $this->assertSame($this->ancienne([
            'unique_simple', 'unique_aller_retour', 'retour_cachee_prise', 'abo_parent_libre', 'abo_parent_lie',
            'abo_enfant_lie', 'abo_enfant_revoque', 'abo_retour_lie', 'abo_retour_revoque',
        ]), $this->nouvelle($this->titulaire));
    }

    public function test_un_autre_agent_voit_la_meme_chose_des_deux_implementations(): void
    {
        $this->assertSame($this->ancienne(['unique_simple', 'unique_aller_retour', 'abo_parent_libre', 'abo_enfant_revoque', 'abo_retour_revoque']), $this->nouvelle($this->autre));
    }

    public function test_un_tiers_sans_lien_voit_la_meme_chose_des_deux_implementations(): void
    {
        $this->assertSame($this->ancienne(['unique_simple', 'unique_aller_retour', 'abo_parent_libre', 'abo_enfant_revoque', 'abo_retour_revoque']), $this->nouvelle($this->tiers));
    }

    public function test_les_trois_observateurs_ne_voient_pas_la_meme_chose(): void
    {
        // Sans ce test, les trois précédents passeraient encore si les deux
        // implémentations renvoyaient toutes deux une liste vide, ou toutes les courses
        // à tout le monde. C'est le test qui rend les autres discriminants.
        $vuTitulaire = $this->nouvelle($this->titulaire);
        $vuAutre = $this->nouvelle($this->autre);

        $this->assertNotEquals($vuTitulaire, $vuAutre);
        $this->assertContains($this->formes['abo_parent_lie'], $vuTitulaire);
        $this->assertNotContains($this->formes['abo_parent_lie'], $vuAutre);
    }

    public function test_les_formes_reservees_au_titulaire_ne_fuient_pas(): void
    {
        $vuAutre = $this->nouvelle($this->autre);

        foreach (['retour_cachee_prise', 'abo_parent_lie', 'abo_enfant_lie', 'abo_retour_lie'] as $forme) {
            $this->assertNotContains(
                $this->formes[$forme],
                $vuAutre,
                "La forme « {$forme} » ne doit pas être visible d'un autre agent.",
            );
        }
    }

    public function test_les_formes_libres_sont_visibles_de_tous(): void
    {
        $vuTiers = $this->nouvelle($this->tiers);

        foreach (['unique_simple', 'unique_aller_retour', 'abo_parent_libre', 'abo_enfant_revoque', 'abo_retour_revoque'] as $forme) {
            $this->assertContains(
                $this->formes[$forme],
                $vuTiers,
                "La forme « {$forme} » doit être visible de tout agent.",
            );
        }
    }

    public function test_une_course_retour_cachee_non_encore_acceptee_n_est_visible_de_personne(): void
    {
        foreach ([$this->titulaire, $this->autre, $this->tiers] as $observateur) {
            $this->assertNotContains(
                $this->formes['retour_cachee_libre'],
                $this->nouvelle($observateur),
                'Une course retour sans subscription_driver_id reste cachée de tous.',
            );
        }
    }

    public function test_l_ordre_est_celui_de_l_heure_de_prise_en_charge(): void
    {
        $vu = $this->nouvelle($this->titulaire);
        $heures = Booking::whereIn('id', $vu)
            ->get()
            ->sortBy(fn ($b) => array_search($b->id, $vu, true))
            ->pluck('pickup_time')
            ->map(fn ($t) => substr((string) $t, 0, 5))
            ->all();

        $triees = $heures;
        sort($triees);

        $this->assertSame($triees, $heures, 'Les courses sortent par heure croissante.');
    }
}
