<?php

namespace Tests\Feature\Booking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Le premier jour d'un abonnement doit tomber dans ses jours de circulation.
 *
 * La règle existait — `calculateEndDate()` lève « La date de départ choisie ne
 * correspond pas aux jours de circulation sélectionnés. » — mais elle partait en
 * `\Exception` générique depuis le service, et le contrôleur public la remplaçait par
 * « La réservation n'a pas pu être enregistrée. Vérifiez votre trajet et réessayez. »
 *
 * Signalé le 2026-09-18 : l'utilisateur a dû ouvrir l'application Blade pour comprendre
 * pourquoi sa réservation était refusée. Un message d'erreur qui n'apprend rien coûte
 * plus cher que pas de message du tout, parce qu'il détourne vers une fausse piste.
 */
class PremierJourDeCirculationTest extends TestCase
{
    use RefreshDatabase;

    private function reservation(array $extra = []): array
    {
        return array_merge([
            'from_location' => 'Cadjehoun', 'to_location' => 'Fidjrosse',
            'from_lat' => 6.36, 'from_lng' => 2.38,
            'to_lat' => 6.37, 'to_lng' => 2.35,
            'pickup_time' => '08:00',
            'phone' => '+22997000000',
            // Obligatoire depuis le 2026-09-29 : voir PublicBookingTermsTest.
            'terms_accepted' => true,
        ], $extra);
    }

    /** Le prochain samedi, à plus de 24 heures. */
    private function prochainSamedi(): string
    {
        return now()->addDays(2)->next(\Carbon\CarbonInterface::SATURDAY)->toDateString();
    }

    private function prochainMardi(): string
    {
        return now()->addDays(2)->next(\Carbon\CarbonInterface::TUESDAY)->toDateString();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Turnstile se retire sans secret : on isole les règles métier.
        config(['services.turnstile.secret' => null]);

        // OpenRouteService est simulé : sans cela, ces tests partaient sur le réseau et
        // passaient ou non selon la connexion (vu le 2026-09-29).
        Http::fake([
            'api.openrouteservice.org/*' => Http::response([
                'routes' => [['summary' => ['distance' => 12_000]]],
            ], 200),
        ]);
    }

    public function test_un_samedi_est_refuse_sur_un_abonnement_lun_ven_avec_un_message_utile(): void
    {
        $reponse = $this->postJson('/api/v1/public/bookings', $this->reservation([
            'days' => 20,
            'week_days' => 'lun_ven',
            'pickup_date' => $this->prochainSamedi(),
        ]));

        $reponse->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');

        // Le message doit désigner le CHAMP fautif et dire quoi corriger.
        $reponse->assertJsonStructure(['errors' => ['pickup_date']]);
        $this->assertStringContainsString(
            'jours de circulation',
            $reponse->json('errors.pickup_date.0'),
            'Le message doit nommer la cause, pas renvoyer un « vérifiez votre trajet ».'
        );
    }

    public function test_un_mardi_passe_sur_le_meme_abonnement(): void
    {
        // Le garde-fou : sans lui, une règle trop large refuserait tout et le test
        // ci-dessus passerait pour la mauvaise raison.
        $this->postJson('/api/v1/public/bookings', $this->reservation([
            'days' => 20,
            'week_days' => 'lun_ven',
            'pickup_date' => $this->prochainMardi(),
        ]))->assertStatus(201);
    }

    public function test_un_samedi_passe_sur_un_abonnement_lun_sam(): void
    {
        $this->postJson('/api/v1/public/bookings', $this->reservation([
            'days' => 20,
            'week_days' => 'lun_sam',
            'pickup_date' => $this->prochainSamedi(),
        ]))->assertStatus(201);
    }

    public function test_une_course_simple_n_est_pas_concernee(): void
    {
        // `days = 1` : pas d'abonnement, donc pas de jours de circulation à respecter.
        $this->postJson('/api/v1/public/bookings', $this->reservation([
            'pickup_date' => $this->prochainSamedi(),
        ]))->assertStatus(201);
    }
}
