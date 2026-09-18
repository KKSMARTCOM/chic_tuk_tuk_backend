<?php

namespace Tests\Feature\Workforce;

use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\LeaveRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Caractérisation des refus de DriverLeaveController::store(), AVANT déplacement.
 *
 * Ces quatre refus sont aujourd'hui des `redirect()->back()->with('error', …)` : sur le
 * chemin API ils ressortiraient en 500, comme les cinq écritures de courses avant le
 * sous-lot 3a. Ils décrivent ici le comportement RÉEL, pour que le déplacement vers une
 * action de domaine puisse être comparé à quelque chose.
 *
 * ⚠️ Les tests visent le contrôleur Blade par HTTP : c'est le seul endroit où cette
 * logique existe encore. Après déplacement, ils devront passer à l'identique.
 */
class RequestLeaveTest extends TestCase
{
    use RefreshDatabase;

    /** Un agent connecté en session Blade, avec ou sans contrat actif. */
    private function agent(bool $avecContrat = true, array $contrat = []): Driver
    {
        $driver = Driver::factory()->create();

        if ($avecContrat) {
            DriverContract::factory()->create(array_merge([
                'driver_id' => $driver->id,
                'start_date' => now()->subMonths(6)->startOfDay(),
                'status' => 'active',
            ], $contrat));
        }

        $this->actingAs($driver->user);

        return $driver;
    }

    private function demander(array $donnees = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('/driver/leaves', array_merge([
            'start_date' => now()->addDays(3)->toDateString(),
            'requested_days' => 2,
        ], $donnees));
    }

    public function test_une_demande_valide_est_enregistree_en_attente(): void
    {
        $driver = $this->agent();

        $this->demander()->assertSessionHas('success');

        $demande = LeaveRequest::where('driver_id', $driver->id)->firstOrFail();
        $this->assertSame('pending', $demande->status);
        $this->assertSame(2, $demande->requested_days);
        // `source` distingue une demande d'agent d'une pause posée par un administrateur.
        $this->assertSame('driver_request', $demande->source);
    }

    public function test_refus_sans_contrat_actif(): void
    {
        $this->agent(avecContrat: false);

        $this->demander()->assertSessionHas('error');

        $this->assertSame(0, LeaveRequest::count());
    }

    public function test_refus_si_la_date_est_a_moins_de_24_heures(): void
    {
        // La règle du Blade : `after_or_equal:tomorrow`.
        $this->agent();

        $this->demander(['start_date' => now()->toDateString()])
            ->assertSessionHasErrors('start_date');

        $this->assertSame(0, LeaveRequest::count());
    }

    public function test_refus_si_la_date_precede_le_debut_du_contrat(): void
    {
        $this->agent(contrat: ['start_date' => now()->addMonth()->startOfDay()]);

        $this->demander(['start_date' => now()->addDays(3)->toDateString()])
            ->assertSessionHas('error');

        $this->assertSame(0, LeaveRequest::count());
    }

    public function test_refus_si_une_demande_est_deja_en_attente(): void
    {
        $driver = $this->agent();
        LeaveRequest::factory()->create(['driver_id' => $driver->id, 'status' => 'pending']);

        $this->demander()->assertSessionHas('error');

        // Toujours une seule : la nouvelle n'a pas été créée.
        $this->assertSame(1, LeaveRequest::count());
    }

    public function test_refus_si_une_pause_est_deja_en_cours(): void
    {
        // L'autre moitié de la même condition : `whereIn(['pending', 'ongoing'])`.
        $driver = $this->agent();
        LeaveRequest::factory()->create(['driver_id' => $driver->id, 'status' => 'ongoing']);

        $this->demander()->assertSessionHas('error');

        $this->assertSame(1, LeaveRequest::count());
    }

    public function test_une_demande_refusee_ne_bloque_pas_une_nouvelle(): void
    {
        // Le garde-fou : sans lui, une condition trop large bloquerait tout et les deux
        // tests ci-dessus passeraient pour la mauvaise raison.
        $driver = $this->agent();
        LeaveRequest::factory()->create(['driver_id' => $driver->id, 'status' => 'rejected']);

        $this->demander()->assertSessionHas('success');

        $this->assertSame(1, LeaveRequest::where('status', 'pending')->count());
    }

    public function test_le_nombre_de_jours_doit_etre_au_moins_un(): void
    {
        $this->agent();

        $this->demander(['requested_days' => 0])->assertSessionHasErrors('requested_days');

        $this->assertSame(0, LeaveRequest::count());
    }
}
