<?php

namespace Tests\Feature\Notification;

use App\Domains\Booking\Application\Actions\AcceptBooking;
use App\Domains\Booking\Application\Actions\CancelBooking;
use App\Domains\Booking\Application\Actions\CompleteBooking;
use App\Domains\Booking\Application\Actions\StartBooking;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Domains\Workforce\Application\Actions\RequestLeave;
use App\Models\Booking;
use App\Models\Driver;
use App\Models\DriverContract;
use App\Models\Notification;
use App\Models\User;
use App\Services\FcmNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Kreait\Firebase\Contract\Messaging;
use Mockery;
use Tests\TestCase;

/**
 * Les actions métier appellent-elles réellement le Notifier ?
 *
 * ⚠️ `NotificationRoutingTest` vérifie la table de routage en isolant le `Notifier`.
 * Cela ne dit RIEN du branchement : on pourrait supprimer tous les appels dans les
 * actions sans qu'un seul de ces tests ne tombe. Ce fichier-ci couvre exactement ce
 * trou — il part de l'action métier et regarde ce qui arrive en base.
 *
 * Il couvre aussi la promesse inverse, la plus importante : **une notification ne doit
 * jamais casser l'action**.
 */
class NotificationHooksTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Driver $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(Messaging::class, Mockery::mock(Messaging::class)->shouldIgnoreMissing());

        $this->admin = User::factory()->profil(Profil::Admin)->create(['name' => 'Admin']);
        $utilisateur = User::factory()->profil(Profil::Driver)->create(['name' => 'Awa Dossou']);
        $this->agent = Driver::factory()->create(['user_id' => $utilisateur->id]);
    }

    private function coursePrete(): Booking
    {
        return Booking::factory()->create([
            'status' => 'pending',
            'driver_id' => null,
            'is_recurring' => false,
            'round_trip' => false,
            'parent_booking_id' => null,
        ]);
    }

    /** Les notifications reçues par l'administrateur. */
    private function pourAdmin(): Collection
    {
        return Notification::where('user_id', $this->admin->id)->get();
    }

    public function test_accepter_une_course_previent_l_administrateur(): void
    {
        $course = $this->coursePrete();

        app(AcceptBooking::class)($course->id, $this->agent->id);

        $this->assertCount(1, $this->pourAdmin());
        $this->assertSame('Course acceptée', $this->pourAdmin()->first()->title);
        $this->assertStringContainsString('Awa Dossou', $this->pourAdmin()->first()->message);
    }

    public function test_demarrer_puis_terminer_previent_a_chaque_etape(): void
    {
        $course = $this->coursePrete();
        app(AcceptBooking::class)($course->id, $this->agent->id);

        app(StartBooking::class)($course->id, $this->agent->id);
        app(CompleteBooking::class)($course->id, $this->agent->id);

        $titres = $this->pourAdmin()->pluck('title')->all();
        $this->assertSame(['Course acceptée', 'Course démarrée', 'Course terminée'], $titres);
    }

    public function test_annuler_une_course_fait_remonter_le_motif(): void
    {
        $course = $this->coursePrete();
        app(AcceptBooking::class)($course->id, $this->agent->id);

        app(CancelBooking::class)($course->id, $this->agent->id, 'Client injoignable');

        $annulation = $this->pourAdmin()->firstWhere('title', 'Course annulée par un agent');
        $this->assertNotNull($annulation, 'aucune notification d\'annulation : le branchement manque');
        $this->assertStringContainsString('Client injoignable', $annulation->message);
    }

    public function test_deposer_une_demande_de_pause_previent_l_administrateur(): void
    {
        DriverContract::factory()->create([
            'driver_id' => $this->agent->id,
            'start_date' => now()->subMonths(6)->startOfDay(),
            'status' => 'active',
        ]);

        app(RequestLeave::class)($this->agent, now()->addDays(3)->toDateString(), 2);

        $this->assertCount(1, $this->pourAdmin());
        $this->assertSame('Nouvelle demande de pause', $this->pourAdmin()->first()->title);
    }

    public function test_une_notification_en_echec_laisse_l_action_aboutir(): void
    {
        // ⚠️ La promesse la plus importante du lot. Ces actions tournent dans des
        // transactions : une exception levée par l'envoi annulerait l'acceptation de la
        // course elle-même. On simule la panne en rendant l'envoi impossible.
        $this->app->bind(FcmNotificationService::class, function () {
            return new class extends FcmNotificationService
            {
                public function sendToUser(User $user, string $title, string $body, array $data = []): void
                {
                    throw new \RuntimeException('Firebase est tombé');
                }
            };
        });

        $course = $this->coursePrete();

        app(AcceptBooking::class)($course->id, $this->agent->id);

        // L'action a abouti malgré la panne de notification.
        $this->assertSame('confirmed', $course->fresh()->status);
        $this->assertSame($this->agent->id, $course->fresh()->driver_id);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
