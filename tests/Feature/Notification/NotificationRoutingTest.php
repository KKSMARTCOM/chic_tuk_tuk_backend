<?php

namespace Tests\Feature\Notification;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Domains\Notification\Application\Notifier;
use App\Models\Booking;
use App\Models\Driver;
use App\Models\LeaveRequest;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleContract;
use App\Models\VehiclePause;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Messaging;
use Mockery;
use Tests\TestCase;

/**
 * Qui reçoit quoi — la règle arrêtée le 2026-09-21, verrouillée.
 *
 * Ces tests ne vérifient PAS que « quelqu'un a été prévenu » : ils vérifient qui, et
 * surtout **qui ne l'a pas été**. C'est l'assertion négative qui compte — un routage
 * trop large ne casse rien de visible, il noie simplement les destinataires jusqu'à ce
 * qu'ils coupent les notifications.
 *
 * On observe la TABLE `notifications` et non les envois Firebase : la trace en base est
 * écrite pour chaque destinataire, avec ou sans appareil enregistré, et c'est elle qui
 * dit la vérité sur le routage.
 */
class NotificationRoutingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin1;

    private User $admin2;

    private User $agent;

    private User $autreAgent;

    private User $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();

        // Firebase n'est jamais joint : seul le routage nous intéresse.
        $this->app->instance(Messaging::class, Mockery::mock(Messaging::class)->shouldIgnoreMissing());

        $this->admin1 = User::factory()->profil(Profil::Admin)->create(['name' => 'Admin Un']);
        $this->admin2 = User::factory()->profil(Profil::Admin)->create(['name' => 'Admin Deux']);
        $this->agent = User::factory()->profil(Profil::Driver)->create(['name' => 'Awa Dossou']);
        $this->autreAgent = User::factory()->profil(Profil::Driver)->create(['name' => 'Kofi Mensah']);
        $this->proprietaire = User::factory()->profil(Profil::Owner)->create(['name' => 'Propriétaire']);
    }

    private function notifier(): Notifier
    {
        return app(Notifier::class);
    }

    /** @return list<string> les noms des destinataires, triés */
    private function destinataires(): array
    {
        return Notification::with('user')->get()
            ->map(fn (Notification $n) => $n->user?->name ?? '?')
            ->sort()->values()->all();
    }

    private function course(): Booking
    {
        return Booking::factory()->create(['booking_number' => 'CTT-12345678']);
    }

    // ----- Actions d'agent : les administrateurs -----------------------------

    public function test_une_course_acceptee_previent_les_administrateurs_et_eux_seuls(): void
    {
        $this->notifier()->bookingAccepted($this->course(), $this->agent);

        $this->assertSame(['Admin Deux', 'Admin Un'], $this->destinataires());
    }

    public function test_l_agent_qui_agit_n_est_pas_prevenu_de_sa_propre_action(): void
    {
        // Un accusé de réception de son propre geste est du bruit, et le bruit finit par
        // faire couper les notifications.
        $this->notifier()->bookingAccepted($this->course(), $this->agent);

        $this->assertDatabaseMissing('notifications', ['user_id' => $this->agent->id]);
    }

    public function test_les_quatre_autres_actions_d_agent_suivent_la_meme_regle(): void
    {
        $course = $this->course();

        $this->notifier()->bookingStarted($course, $this->agent);
        $this->notifier()->bookingCompleted($course, $this->agent);
        $this->notifier()->bookingCancelled($course, $this->agent, 'Client absent');
        $this->notifier()->subscriptionRevoked($course, $this->agent);

        // Quatre événements, deux administrateurs : huit notifications, et aucune ailleurs.
        $this->assertSame(8, Notification::count());
        $this->assertSame(8, Notification::whereIn('user_id', [$this->admin1->id, $this->admin2->id])->count());
    }

    public function test_le_motif_d_annulation_voyage_avec_la_notification(): void
    {
        // Sans le motif, l'administrateur doit ouvrir la course pour savoir ce qui s'est
        // passé — ce que la notification est justement censée lui épargner.
        $this->notifier()->bookingCancelled($this->course(), $this->agent, 'Client absent');

        $this->assertStringContainsString('Client absent', Notification::first()->message);
    }

    public function test_une_nouvelle_reservation_previent_les_agents_e_t_les_administrateurs(): void
    {
        // ⚠️ Les administrateurs ont été ajoutés le 2026-09-22 : ils ne voyaient une
        // nouvelle réservation qu'en ouvrant l'application, alors que ce sont eux qui
        // affectent les courses qu'aucun agent ne prend.
        $this->notifier()->bookingCreated($this->course());

        $this->assertSame(
            ['Admin Deux', 'Admin Un', 'Awa Dossou', 'Kofi Mensah'],
            $this->destinataires()
        );
    }

    public function test_agents_et_administrateurs_ne_recoivent_pas_le_meme_message(): void
    {
        /*
         * ⚠️ Deux publics, deux messages, deux destinations. L'agent est INVITÉ à prendre
         * la course et repart vers les courses disponibles ; l'administrateur est INFORMÉ
         * qu'elle est arrivée et repart vers son dossier. Un envoi commun aurait renvoyé
         * les administrateurs vers un écran d'agent que leur jeton refuse.
         */
        $course = $this->course();

        $this->notifier()->bookingCreated($course);

        $parPersonne = Notification::with('user')->get()->keyBy(fn ($n) => $n->user->name);

        $this->assertSame('/driver/bookings/available', $parPersonne['Awa Dossou']->data['url']);
        $this->assertSame("/admin/bookings/{$course->id}", $parPersonne['Admin Un']->data['url']);
    }

    // ----- Pauses agent ------------------------------------------------------

    public function test_une_demande_de_pause_remonte_aux_administrateurs(): void
    {
        $this->notifier()->leaveRequested($this->demandeDe($this->agent));

        $this->assertSame(['Admin Deux', 'Admin Un'], $this->destinataires());
    }

    public function test_une_pause_validee_previent_l_agent_seul_et_jamais_les_administrateurs(): void
    {
        // ⚠️ Le point le plus facile à rater. « Les administrateurs sont prévenus de
        // toutes les actions agent » ne veut pas dire qu'ils reçoivent tout : ici c'est
        // l'un d'eux qui vient de valider.
        $this->notifier()->leaveApproved($this->demandeDe($this->agent));

        $this->assertSame(['Awa Dossou'], $this->destinataires());
    }

    public function test_une_pause_refusee_porte_son_motif_a_l_agent(): void
    {
        $demande = $this->demandeDe($this->agent);
        $demande->update(['status' => 'rejected', 'rejection_reason' => 'Période trop chargée']);

        $this->notifier()->leaveRejected($demande->refresh());

        $this->assertSame(['Awa Dossou'], $this->destinataires());
        $this->assertStringContainsString('Période trop chargée', Notification::first()->message);
    }

    public function test_un_autre_agent_n_est_jamais_prevenu_d_une_pause(): void
    {
        $this->notifier()->leaveApproved($this->demandeDe($this->agent));

        $this->assertDatabaseMissing('notifications', ['user_id' => $this->autreAgent->id]);
    }

    // ----- Pauses véhicule ---------------------------------------------------

    public function test_une_pause_vehicule_previent_le_proprietaire_avec_le_motif(): void
    {
        // Le motif est le cœur du message : un propriétaire qui voit son véhicule à
        // l'arrêt sans savoir pourquoi appelle l'administration.
        $this->notifier()->vehiclePaused($this->pauseVehicule('technical', 'Boîte de vitesses'));

        $this->assertSame(['Propriétaire'], $this->destinataires());

        $message = Notification::first()->message;
        $this->assertStringContainsString('Problème technique', $message);
        $this->assertStringContainsString('Boîte de vitesses', $message);
    }

    public function test_une_pause_vehicule_ne_previent_ni_les_admins_ni_les_agents(): void
    {
        $this->notifier()->vehiclePaused($this->pauseVehicule('accident'));

        $this->assertSame(1, Notification::count());
        $this->assertSame($this->proprietaire->id, Notification::first()->user_id);
    }

    public function test_un_autre_proprietaire_n_est_pas_prevenu(): void
    {
        // La portée la plus dangereuse : un propriétaire ne doit rien apprendre du parc
        // d'un autre.
        $autre = User::factory()->profil(Profil::Owner)->create(['name' => 'Autre propriétaire']);

        $this->notifier()->vehiclePaused($this->pauseVehicule('legal'));

        $this->assertDatabaseMissing('notifications', ['user_id' => $autre->id]);
    }

    public function test_la_reprise_du_vehicule_previent_aussi_le_proprietaire(): void
    {
        $pause = $this->pauseVehicule('technical');
        $pause->update(['end_date' => now()->toDateString()]);

        $this->notifier()->vehiclePauseEnded($pause->refresh());

        $this->assertSame(['Propriétaire'], $this->destinataires());
    }

    // ----- Paiements ---------------------------------------------------------

    public function test_un_paiement_valide_ne_previent_que_l_agent_paye(): void
    {
        // ⚠️ Surtout PAS les administrateurs : ce n'est pas une action d'agent, et c'est
        // l'un d'eux qui vient de valider.
        $this->notifier()->paymentValidated($this->paiementDe($this->agent));

        $this->assertSame(['Awa Dossou'], $this->destinataires());
    }

    public function test_la_notification_de_paiement_nomme_la_date_du_paiement(): void
    {
        // « Votre paiement a été validé » n'aide pas un agent qui en attend plusieurs :
        // la date est ce qui permet de savoir DUQUEL on parle.
        $paiement = $this->paiementDe($this->agent, '2026-09-12');

        $this->notifier()->paymentValidated($paiement);

        $this->assertStringContainsString('12/09/2026', Notification::first()->message);
    }

    public function test_un_paiement_annule_previent_aussi_l_agent(): void
    {
        // Un agent qui comptait sur cette somme doit l'apprendre autrement qu'en s'en
        // apercevant.
        $this->notifier()->paymentCancelled($this->paiementDe($this->agent));

        $this->assertSame(['Awa Dossou'], $this->destinataires());
        $this->assertStringContainsString('annulé', Notification::first()->message);
    }

    public function test_un_paiement_sans_destinataire_identifiable_ne_fait_rien_echouer(): void
    {
        // La notification est un effet de bord : elle ne doit JAMAIS empêcher
        // l'enregistrement d'un paiement.
        //
        // ⚠️ `make` et non `create` : `payments.driver_id` est NOT NULL, donc ce cas ne
        // peut pas exister en base aujourd'hui. Le garde protège la situation voisine et
        // bien réelle — un agent dont le compte utilisateur a été supprimé — et rien ne
        // dit que la contrainte tiendra pour toujours.
        $paiement = Payment::factory()->make(['driver_id' => null]);

        $this->notifier()->paymentValidated($paiement);

        $this->assertSame(0, Notification::count());
    }

    // ----- Destinations ------------------------------------------------------

    public function test_les_notifications_d_administrateur_menent_aux_ecrans_livres(): void
    {
        /*
         * ⚠️ Elles n'en portaient aucune tant que l'espace admin du front n'existait pas :
         * une destination vers un écran inexistant est pire qu'aucune destination. Les
         * écrans de réservations ont été livrés le 2026-09-22.
         *
         * ⚠️ N'ajouter une destination qu'une fois l'écran EN LIGNE, pas une fois écrit :
         * le backend se déploie avant le front, et une notification partie entre les deux
         * mènerait à un 404 que personne ne saurait expliquer.
         */
        $course = $this->course();

        $this->notifier()->bookingAccepted($course, $this->agent);

        $this->assertSame("/admin/bookings/{$course->id}", Notification::first()->data['url']);
    }

    public function test_les_notifications_d_agent_et_de_proprietaire_mènent_a_un_ecran(): void
    {
        $this->notifier()->leaveApproved($this->demandeDe($this->agent));
        $this->assertSame('/driver/leaves', Notification::latest('id')->first()->data['url']);

        Notification::query()->delete();

        $pause = $this->pauseVehicule('technical');
        $this->notifier()->vehiclePaused($pause);
        $this->assertStringContainsString(
            "/owner/vehicles/{$pause->vehicle_id}/pauses",
            Notification::latest('id')->first()->data['url'],
        );
    }

    // ----- Fixtures ----------------------------------------------------------

    private function demandeDe(User $user): LeaveRequest
    {
        $driver = Driver::factory()->create(['user_id' => $user->id]);

        return LeaveRequest::factory()->create([
            'driver_id' => $driver->id,
            'start_date' => now()->addDays(3)->toDateString(),
            'requested_days' => 2,
            'status' => 'pending',
        ]);
    }

    private function paiementDe(User $user, ?string $date = null): Payment
    {
        $driver = Driver::factory()->create(['user_id' => $user->id]);

        return Payment::factory()->create([
            'driver_id' => $driver->id,
            'payment_type' => 'commission',
            'payment_date' => $date ?? now()->toDateString(),
        ]);
    }

    private function pauseVehicule(string $motif, ?string $notes = null): VehiclePause
    {
        $vehicule = Vehicle::factory()->create(['owner_id' => $this->proprietaire->id]);
        // `vehicle_pauses.vehicle_contract_id` est NOT NULL : une pause n'existe pas sans
        // contrat de véhicule, la base l'impose.
        $contrat = VehicleContract::factory()->create(['vehicle_id' => $vehicule->id]);

        return VehiclePause::create([
            'id' => (string) Str::uuid(),
            'vehicle_id' => $vehicule->id,
            'vehicle_contract_id' => $contrat->id,
            'start_date' => now()->toDateString(),
            'reason_type' => $motif,
            'reason_notes' => $notes,
            'is_auto' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
