<?php

namespace Tests\Feature\Audit;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Booking\Application\Actions\ExpireStaleBookings;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Booking;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Le journal d'activité : qui a fait quoi (2026-09-29).
 *
 * ⚠️ Le cas qui justifie de tracer depuis les contrôleurs : l'affectation d'un agent par
 * l'administrateur passe par `AcceptBooking`, comme une course prise par l'agent. Une trace
 * écrite dans l'action dirait « l'agent a accepté » quand l'administrateur a affecté.
 */
class ActivityJournalTest extends TestCase
{
    use RefreshDatabase;

    private function user(Profil $profil, string $name, array $permissions = []): User
    {
        $user = User::factory()->profil($profil)->create([
            'name' => $name,
            'password' => Hash::make('bon-mot-de-passe'),
        ]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user;
    }

    private function token(User $user): string
    {
        Auth::forgetGuards();

        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');
    }

    private function as(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    private function driver(string $name): Driver
    {
        $user = User::factory()->profil(Profil::Driver)->create(['name' => $name]);

        return Driver::factory()->create(['user_id' => $user->id]);
    }

    private function last(string $event): Activity
    {
        return Activity::query()->where('event', $event)->latest('id')->firstOrFail();
    }

    // ----- Connexions --------------------------------------------------------------------

    public function test_une_connexion_est_tracee_avec_son_auteur_et_son_adresse(): void
    {
        $awa = $this->user(Profil::Admin, 'Awa Dossou');

        $this->token($awa);

        $entry = $this->last('auth.login');
        $this->assertSame('a ouvert une session', $entry->description);
        $this->assertSame($awa->id, $entry->causer_id);
        $this->assertSame('Awa Dossou', $entry->properties['actor']);
        $this->assertSame('127.0.0.1', $entry->properties['ip']);
    }

    public function test_une_connexion_refusee_est_tracee_avec_l_adresse_saisie(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'inconnu@example.test', 'password' => 'x'])
            ->assertUnprocessable();

        $entry = $this->last('auth.login_failed');
        $this->assertStringContainsString('inconnu@example.test', $entry->description);
        $this->assertStringContainsString('identifiants incorrects', $entry->description);
        $this->assertNull($entry->causer_id);
    }

    public function test_un_compte_verrouille_est_trace_comme_tel(): void
    {
        $user = $this->user(Profil::Driver, 'Koffi');
        $user->forceFill(['locked_until' => now()->addMinutes(5)])->save();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'bon-mot-de-passe'])
            ->assertStatus(423);

        $this->assertSame('locked', $this->last('auth.login_failed')->properties['reason']);
    }

    public function test_la_deconnexion_est_tracee(): void
    {
        $awa = $this->user(Profil::Admin, 'Awa Dossou');

        $this->as($this->token($awa))->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertSame($awa->id, $this->last('auth.logout')->causer_id);
    }

    // ----- Réservations ------------------------------------------------------------------

    public function test_une_affectation_par_l_admin_est_tracee_comme_telle_et_une_seule_fois(): void
    {
        $awa = $this->user(Profil::Admin, 'Awa Dossou', ['edit-bookings', 'view-bookings']);
        $koffi = $this->driver('Koffi Mensah');
        $booking = Booking::factory()->create();

        $this->as($this->token($awa))
            ->postJson("/api/v1/admin/bookings/{$booking->id}/assign-driver", ['driver_id' => $koffi->id])
            ->assertOk();

        $entry = $this->last('booking.driver_assigned');
        $this->assertSame('Awa Dossou', $entry->properties['actor']);
        $this->assertSame("a affecté Koffi Mensah à la course {$booking->booking_number}", $entry->description);
        $this->assertSame($booking->id, $entry->subject_id);
        // Pas de fausse ligne « Koffi a accepté » : AcceptBooking n'écrit rien.
        $this->assertSame(0, Activity::query()->where('event', 'booking.accepted')->count());
    }

    public function test_un_agent_qui_accepte_une_course_est_trace_a_son_nom(): void
    {
        $koffiUser = $this->user(Profil::Driver, 'Koffi Mensah', ['view-bookings', 'edit-bookings']);
        Driver::factory()->create(['user_id' => $koffiUser->id]);
        $booking = Booking::factory()->create();

        $this->as($this->token($koffiUser))
            ->postJson("/api/v1/driver/bookings/{$booking->id}/accept")
            ->assertOk();

        $entry = $this->last('booking.accepted');
        $this->assertSame('Koffi Mensah', $entry->properties['actor']);
        $this->assertSame("a accepté la course {$booking->booking_number}", $entry->description);
    }

    public function test_un_changement_de_statut_garde_l_avant_et_l_apres(): void
    {
        $awa = $this->user(Profil::Admin, 'Awa Dossou', ['edit-bookings', 'view-bookings']);
        $booking = Booking::factory()->confirmed($this->driver('Koffi'))->create();

        $this->as($this->token($awa))
            ->postJson("/api/v1/admin/bookings/{$booking->id}/status", ['status' => 'in_progress'])
            ->assertOk();

        $entry = $this->last('booking.status_changed');
        $this->assertSame(['confirmed', 'in_progress'], [$entry->properties['from'], $entry->properties['to']]);
        $this->assertStringContainsString('« En cours »', $entry->description);
    }

    public function test_une_suppression_garde_le_numero_de_la_reservation_disparue(): void
    {
        $awa = $this->user(Profil::Admin, 'Awa Dossou', ['delete-bookings']);
        // Seule une réservation annulée ou expirée se supprime.
        $booking = Booking::factory()->create(['status' => 'cancelled']);
        $number = $booking->booking_number;

        $this->as($this->token($awa))
            ->deleteJson("/api/v1/admin/bookings/{$booking->id}")
            ->assertNoContent();

        $entry = $this->last('booking.deleted');
        $this->assertSame("a supprimé la réservation {$number}", $entry->description);
        $this->assertSame($number, $entry->properties['booking_number']);
    }

    public function test_une_reservation_expiree_est_tracee_au_nom_du_systeme(): void
    {
        $booking = Booking::factory()->create([
            'status' => 'pending',
            'pickup_date' => now()->subDays(3)->toDateString(),
            'pickup_time' => '08:00',
        ]);

        app(ExpireStaleBookings::class)();

        $entry = $this->last('booking.expired');
        $this->assertSame(ActivityJournal::SYSTEM, $entry->properties['actor']);
        $this->assertNull($entry->causer_id);
        $this->assertSame($booking->id, $entry->subject_id);
    }

    public function test_une_reservation_en_ligne_est_tracee_au_nom_du_client(): void
    {
        $booking = Booking::factory()->create(['client_name' => 'Rachida Bello']);

        app(ActivityJournal::class)->bookingCreatedOnline($booking);

        $entry = $this->last('booking.created');
        $this->assertSame('Rachida Bello (en ligne)', $entry->properties['actor']);
        $this->assertSame("a réservé en ligne la course {$booking->booking_number}", $entry->description);
    }

    // ----- Réglages ----------------------------------------------------------------------

    public function test_un_changement_de_tarifs_ne_garde_que_ce_qui_a_change(): void
    {
        $awa = $this->user(Profil::Admin, 'Awa Dossou', ['manage-business-settings']);

        $this->as($this->token($awa))->putJson('/api/v1/admin/settings/pricing', [
            'base_price' => 1000,
            'price_per_km' => 200,
            'time_surcharge' => 1000,
            'surcharge_free_start_hour' => 6,
            'surcharge_free_end_hour' => 10,
        ])->assertOk();

        $this->assertSame(
            ['price_per_km' => ['from' => 150, 'to' => 200]],
            $this->last('settings.pricing_updated')->properties['changes'],
        );
    }

    // ----- L'écran -----------------------------------------------------------------------

    public function test_le_journal_est_reserve_a_sa_permission(): void
    {
        $awa = $this->user(Profil::Admin, 'Awa Dossou', ['view-bookings', 'manage-settings']);

        $this->as($this->token($awa))->getJson('/api/v1/admin/activity-log')->assertForbidden();
    }

    public function test_le_journal_se_lit_du_plus_recent_au_plus_ancien_et_se_filtre(): void
    {
        $awa = $this->user(Profil::Admin, 'Awa Dossou', ['view-activity-log', 'edit-bookings', 'view-bookings']);
        $token = $this->token($awa);
        $booking = Booking::factory()->create();
        $this->as($token)->postJson("/api/v1/admin/bookings/{$booking->id}/assign-driver", ['driver_id' => $this->driver('Koffi')->id]);

        $all = $this->as($token)->getJson('/api/v1/admin/activity-log')->assertOk();
        $this->assertSame('booking.driver_assigned', $all->json('entries.0.event'));
        $this->assertSame('Agent affecté', $all->json('entries.0.event_label'));
        $this->assertSame('booking', $all->json('entries.0.subject_type'));
        $this->assertNotEmpty($all->json('events'));

        // Par groupe d'événements : le préfixe `auth.` ne rend que les connexions.
        $auth = $this->as($token)->getJson('/api/v1/admin/activity-log?filter[event]=auth.')->assertOk();
        $this->assertSame(['auth.login'], array_values(array_unique(array_column($auth->json('entries'), 'event'))));

        // Par recherche dans la phrase.
        $search = $this->as($token)->getJson("/api/v1/admin/activity-log?filter[search]={$booking->booking_number}")->assertOk();
        $this->assertSame(1, $search->json('pagination.total'));

        // Par objet : tout ce qui est arrivé à cette réservation.
        $subject = $this->as($token)->getJson("/api/v1/admin/activity-log?filter[subject_id]={$booking->id}")->assertOk();
        $this->assertSame(1, $subject->json('pagination.total'));
    }

    public function test_le_journal_garde_douze_mois(): void
    {
        $this->assertSame(365, config('activitylog.delete_records_older_than_days'));
    }
}
