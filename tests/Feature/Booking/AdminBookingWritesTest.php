<?php

namespace Tests\Feature\Booking;

use App\Domains\Booking\Application\Actions\AssignDriverToBooking;
use App\Domains\Booking\Application\Actions\ChangeBookingStatus;
use App\Domains\Booking\Application\Actions\ListAssignableDrivers;
use App\Domains\Booking\Application\Actions\RemoveDriverFromBooking;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\Driver;
use App\Models\User;
use App\Shared\Http\ApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les quatre écritures de l'administration sur une réservation.
 *
 * Ce fichier couvre surtout DEUX DÉFAUTS du chemin Blade, corrigés en transposant. Ils ne
 * se voyaient ni dans la réponse ni à l'écran, et c'est ce qui les rendait durables :
 *
 *  1. clôturer depuis l'administration ne créait aucune commission ;
 *  2. toucher au statut d'un abonnement annulait toutes ses courses à venir.
 */
class AdminBookingWritesTest extends TestCase
{
    use RefreshDatabase;

    private function driver(string $name = 'Kofi Mensah', bool $available = true, bool $active = true): Driver
    {
        $user = User::factory()->profil(Profil::Driver)->create(['name' => $name, 'is_active' => $active]);

        return Driver::factory()->create(['user_id' => $user->id, 'is_available' => $available]);
    }

    private function booking(array $attributes = []): Booking
    {
        return Booking::factory()->create($attributes);
    }

    // ----- Clôturer ----------------------------------------------------------

    public function test_cloturer_depuis_l_administration_cree_la_commission(): void
    {
        /*
         * ⚠️ LE défaut. Le contrôleur Blade traverse `BookingService::update()` avec
         * `_partial`, qui se contente de poser la colonne `status` : ni `driver_earning`,
         * ni `commission`, ni ligne comptable, ni `completed_at`, ni `total_trips`. La
         * course s'affichait « Terminée » comme les autres, l'agent n'avait rien gagné et
         * l'entreprise ne devait rien.
         */
        $driver = $this->driver();
        $booking = $this->booking([
            'driver_id' => $driver->id,
            'status' => 'in_progress',
            'base_price' => 3000,
        ]);

        app(ChangeBookingStatus::class)($booking, 'completed');

        $booking->refresh();

        $this->assertSame('completed', $booking->status);
        $this->assertNotNull($booking->completed_at, 'la date de clôture n\'a pas été posée');
        $this->assertNotNull($booking->commission, 'aucune commission n\'a été calculée');
        $this->assertNotNull($booking->driver_earning, 'le gain de l\'agent n\'a pas été calculé');
        $this->assertSame(
            1,
            Commission::where('booking_id', $booking->id)->count(),
            'aucune ligne comptable n\'a été créée'
        );
    }

    public function test_une_course_sans_agent_ne_peut_pas_etre_cloturee(): void
    {
        // Conséquence assumée du correctif : on ne clôture pas une course que personne
        // n'a conduite. Le Blade l'acceptait et produisait un trajet à zéro franc.
        $booking = $this->booking(['driver_id' => null, 'status' => 'in_progress']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Cette course n\'a pas d\'agent');

        app(ChangeBookingStatus::class)($booking, 'completed');
    }

    public function test_une_course_pas_encore_commencee_ne_peut_pas_etre_cloturee(): void
    {
        $driver = $this->driver();
        $booking = $this->booking(['driver_id' => $driver->id, 'status' => 'confirmed']);

        $this->expectException(ApiException::class);
        // Le message NOMME ce qui est possible, plutôt que de laisser chercher.
        $this->expectExceptionMessage('Depuis « Confirmée », seul « En cours » ou « Annulée » est possible.');

        app(ChangeBookingStatus::class)($booking, 'completed');
    }

    // ----- Le cycle de vie ---------------------------------------------------

    public function test_une_course_terminee_ne_change_plus_de_statut(): void
    {
        /*
         * ⚠️ Le contrôleur Blade validait `status` par un simple `in:...` : n'importe quel
         * statut pouvait suivre n'importe quel autre. On remettait « En attente » une
         * course terminée — qui a pourtant produit une commission et un gain d'agent —
         * sans rien défaire de ces effets. Signalé le 2026-09-22.
         */
        $driver = $this->driver();
        $booking = $this->booking(['driver_id' => $driver->id, 'status' => 'completed']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('close');

        app(ChangeBookingStatus::class)($booking, 'pending');
    }

    public function test_une_course_annulee_ne_se_reprend_pas(): void
    {
        $booking = $this->booking(['status' => 'cancelled']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('close');

        app(ChangeBookingStatus::class)($booking, 'in_progress');
    }

    public function test_une_course_expiree_ne_se_ranime_pas(): void
    {
        // Elle a raté son heure de départ : la rouvrir fabriquerait une course dont
        // l'historique ment.
        $booking = $this->booking(['status' => 'expired']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('close');

        app(ChangeBookingStatus::class)($booking, 'pending');
    }

    public function test_le_cycle_normal_reste_possible(): void
    {
        // Le pendant des trois tests ci-dessus : on ne ferme pas le chemin ordinaire.
        $driver = $this->driver();
        $booking = $this->booking(['driver_id' => $driver->id, 'status' => 'confirmed']);

        app(ChangeBookingStatus::class)($booking, 'in_progress');
        $this->assertSame('in_progress', $booking->refresh()->status);

        app(ChangeBookingStatus::class)($booking, 'completed');
        $this->assertSame('completed', $booking->refresh()->status);
    }

    // ----- Annuler -----------------------------------------------------------

    public function test_changer_le_statut_d_un_abonnement_ne_touche_pas_a_ses_courses_filles(): void
    {
        /*
         * ⚠️ LE second défaut. Dans `BookingService::update()`, la branche
         * `is_subscription_parent` annule les enfants `pending` et `confirmed` à CHAQUE
         * mise à jour partielle. Confirmer un abonnement — le geste le plus banal —
         * effaçait donc tout son calendrier à venir, sans que rien ne le dise.
         */
        $parent = $this->booking(['is_recurring' => true, 'parent_booking_id' => null, 'status' => 'pending']);
        $fille = $this->booking([
            'parent_booking_id' => $parent->id,
            'is_recurring' => false,
            'status' => 'pending',
        ]);

        app(ChangeBookingStatus::class)($parent, 'confirmed');

        $this->assertSame('pending', $fille->refresh()->status, 'la course fille a été annulée');
    }

    public function test_annuler_un_abonnement_annule_bien_ses_courses_filles(): void
    {
        // Le pendant du test précédent : la propagation n'est pas supprimée, elle est
        // ramenée au seul geste qui la justifie.
        $parent = $this->booking(['is_recurring' => true, 'parent_booking_id' => null, 'status' => 'confirmed']);
        $fille = $this->booking([
            'parent_booking_id' => $parent->id,
            'is_recurring' => false,
            'status' => 'pending',
        ]);

        app(ChangeBookingStatus::class)($parent, 'cancelled', 'Client injoignable');

        $this->assertSame('cancelled', $fille->refresh()->status);
        $this->assertSame('cancelled', $parent->refresh()->status);
    }

    public function test_le_motif_saisi_est_conserve(): void
    {
        // ⚠️ `BookingService::update()` écrit `$validated['cancellation_reason']`, où
        // `$validated` n'existe pas dans sa portée : le motif saisi était TOUJOURS
        // remplacé par « Abonnement annulé ».
        $booking = $this->booking(['status' => 'confirmed']);

        app(ChangeBookingStatus::class)($booking, 'cancelled', 'Client injoignable');

        $this->assertSame('Client injoignable', $booking->refresh()->cancellation_reason);
    }

    public function test_une_course_en_cours_ne_s_annule_pas(): void
    {
        // Elle se termine, elle ne s'annule pas — c'est ce que le Blade refusait déjà, et
        // que la matrice de transitions dit désormais en nommant la seule issue.
        $driver = $this->driver();
        $booking = $this->booking(['driver_id' => $driver->id, 'status' => 'in_progress']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Depuis « En cours », seul « Terminée » est possible.');

        app(ChangeBookingStatus::class)($booking, 'cancelled');
    }

    // ----- Affecter et retirer -----------------------------------------------

    public function test_affecter_un_agent_passe_par_le_meme_chemin_que_l_acceptation(): void
    {
        // ⚠️ L'affectation traverse `BookingService::take()`, qui propage le titulaire sur
        // la course retour et les enfants d'abonnement. Poser `driver_id` à la main
        // laisserait le retour invisible de tous.
        $driver = $this->driver();
        $booking = $this->booking(['driver_id' => null, 'status' => 'pending']);

        app(AssignDriverToBooking::class)($booking, $driver->id);

        $this->assertSame($driver->id, $booking->refresh()->driver_id);
        $this->assertSame('confirmed', $booking->status);
    }

    public function test_une_course_expiree_ne_recoit_plus_d_agent(): void
    {
        // ⚠️ `$canAssign` du Blade exige `status === 'pending'`, mais cette règle ne
        // vivait que dans le gabarit : un appel direct affectait un agent à une course
        // expirée, qui a pourtant raté son heure de départ.
        $driver = $this->driver();
        $booking = $this->booking(['driver_id' => null, 'status' => 'expired']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('en attente');

        app(AssignDriverToBooking::class)($booking, $driver->id);
    }

    public function test_une_course_annulee_ne_recoit_plus_d_agent(): void
    {
        $driver = $this->driver();
        $booking = $this->booking(['driver_id' => null, 'status' => 'cancelled']);

        $this->expectException(ApiException::class);

        app(AssignDriverToBooking::class)($booking, $driver->id);
    }

    public function test_une_course_fille_d_abonnement_revient_a_son_titulaire(): void
    {
        /*
         * ⚠️ La troisième condition de `$canAssign`, la plus facile à perdre. Les courses
         * filles reviennent au TITULAIRE de l'abonnement ; en affecter une à quelqu'un
         * d'autre briserait la chaîne, et le prochain enfant créé par le cron repartirait
         * quand même vers le titulaire.
         */
        $driver = $this->driver();
        $parent = $this->booking(['is_recurring' => true, 'parent_booking_id' => null]);
        $fille = $this->booking([
            'parent_booking_id' => $parent->id,
            'is_recurring' => false,
            'driver_id' => null,
            'status' => 'pending',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('agent titulaire');

        app(AssignDriverToBooking::class)($fille, $driver->id);
    }

    public function test_une_course_terminee_garde_son_agent(): void
    {
        // Retirer l'agent d'une course terminée effacerait celui à qui la commission a
        // été versée.
        $driver = $this->driver();
        $booking = $this->booking(['driver_id' => $driver->id, 'status' => 'completed']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('close');

        app(RemoveDriverFromBooking::class)($booking);
    }

    public function test_un_compte_desactive_ne_recoit_pas_de_course(): void
    {
        $driver = $this->driver('Compte fermé', available: true, active: false);
        $booking = $this->booking(['driver_id' => null, 'status' => 'pending']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Cet agent n\'est pas disponible.');

        app(AssignDriverToBooking::class)($booking, $driver->id);
    }

    public function test_retirer_l_agent_remet_la_course_au_vivier(): void
    {
        $driver = $this->driver();
        $booking = $this->booking(['driver_id' => $driver->id, 'status' => 'confirmed']);

        app(RemoveDriverFromBooking::class)($booking);

        $booking->refresh();

        $this->assertNull($booking->driver_id);
        $this->assertSame('pending', $booking->status, 'la course n\'est pas redevenue disponible');
    }

    public function test_retirer_l_agent_d_un_abonnement_retire_aussi_le_titulaire(): void
    {
        // Sans cela, l'abonnement resterait réservé à quelqu'un qui n'y est plus affecté,
        // donc invisible de tous les autres agents.
        $driver = $this->driver();
        $parent = $this->booking([
            'is_recurring' => true,
            'parent_booking_id' => null,
            'driver_id' => $driver->id,
            'subscription_driver_id' => $driver->id,
            'status' => 'confirmed',
        ]);

        app(RemoveDriverFromBooking::class)($parent);

        $this->assertNull($parent->refresh()->subscription_driver_id);
    }

    public function test_un_abonnement_ayant_des_courses_filles_garde_son_agent(): void
    {
        $driver = $this->driver();
        $parent = $this->booking([
            'is_recurring' => true,
            'parent_booking_id' => null,
            'driver_id' => $driver->id,
            'subscription_driver_id' => $driver->id,
            'status' => 'confirmed',
        ]);
        $this->booking(['parent_booking_id' => $parent->id, 'is_recurring' => false, 'status' => 'pending']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Des courses filles existent déjà');

        app(RemoveDriverFromBooking::class)($parent);
    }

    // ----- Les agents affectables --------------------------------------------

    public function test_seuls_les_agents_reellement_disponibles_sont_proposes(): void
    {
        /*
         * ⚠️ La fenêtre Blade annonce « Sélectionnez un Agent disponible » et appelle
         * `/admin/drivers` avec `available=1`, un paramètre que le contrôleur ne lit pas :
         * elle listait donc tout le monde, y compris les agents en pause et les comptes
         * désactivés, et l'affectation n'échouait qu'ensuite.
         */
        $this->driver('Disponible', available: true, active: true);
        $this->driver('En pause', available: false, active: true);
        $this->driver('Compte fermé', available: true, active: false);

        $names = app(ListAssignableDrivers::class)()
            ->map(fn (Driver $d) => $d->user?->name)
            ->all();

        $this->assertSame(['Disponible'], $names);
    }

    public function test_un_agent_occupe_reste_proposable_avec_son_nombre_de_courses(): void
    {
        // On n'écarte PAS un agent qui a déjà des courses : un administrateur affecte
        // souvent à l'avance, et les exclure viderait la liste aux heures de pointe.
        // Le nombre est renvoyé pour que le choix se fasse en connaissance de cause.
        $driver = $this->driver('Occupé');
        $this->booking(['driver_id' => $driver->id, 'status' => 'in_progress']);
        $this->booking(['driver_id' => $driver->id, 'status' => 'confirmed']);
        $this->booking(['driver_id' => $driver->id, 'status' => 'completed']);

        $found = app(ListAssignableDrivers::class)()->firstWhere('id', $driver->id);

        $this->assertNotNull($found, 'un agent occupé a disparu de la liste');
        // La course terminée ne compte pas : elle ne l'occupe plus.
        $this->assertSame(2, (int) $found->active_bookings_count);
    }
}
