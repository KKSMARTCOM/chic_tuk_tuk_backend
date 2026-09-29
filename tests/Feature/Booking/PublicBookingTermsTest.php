<?php

namespace Tests\Feature\Booking;

use App\Domains\Booking\Domain\Terms;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Les conditions générales d'utilisation, acceptées à la réservation (2026-09-29).
 *
 * La case se coche dans le formulaire du landing, pour TOUTE réservation ; l'API la
 * refuse absente, et garde la preuve — la date d'acceptation et la version des CGU — sur
 * la réservation. C'est ce qui sert en cas de litige sur un remboursement (article 7.2).
 */
class PublicBookingTermsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // OpenRouteService est simulé : sans cela, ces tests partaient sur le réseau et
        // passaient ou non selon la connexion (vu le 2026-09-29).
        Http::fake([
            'api.openrouteservice.org/*' => Http::response([
                'routes' => [['summary' => ['distance' => 12_000]]],
            ], 200),
        ]);
    }

    private function reservation(array $extra = []): array
    {
        return array_merge([
            'from_location' => 'Cadjehoun', 'to_location' => 'Fidjrosse',
            'from_lat' => 6.36, 'from_lng' => 2.38,
            'to_lat' => 6.37, 'to_lng' => 2.35,
            'pickup_date' => now()->addDays(3)->toDateString(),
            'pickup_time' => '08:00',
            'phone' => '+22997000000',
            'terms_accepted' => true,
        ], $extra);
    }

    public function test_a_booking_without_the_terms_is_refused_on_that_field(): void
    {
        foreach ([[], ['terms_accepted' => false]] as $case) {
            $payload = array_merge($this->reservation(), $case);
            if ($case === []) {
                unset($payload['terms_accepted']);
            }

            $this->postJson('/api/v1/public/bookings', $payload)
                ->assertStatus(422)
                ->assertJsonPath('errors.terms_accepted.0', 'Veuillez accepter les conditions générales d\'utilisation.');
        }

        $this->assertSame(0, Booking::count());
    }

    public function test_the_acceptance_is_kept_on_the_booking_with_the_terms_version(): void
    {
        $this->travelTo(now()->setTime(10, 30));

        $number = $this->postJson('/api/v1/public/bookings', $this->reservation())->assertStatus(201)->json('booking_number');

        $booking = Booking::where('booking_number', $number)->firstOrFail();
        $this->assertTrue($booking->terms_accepted_at->equalTo(now()));
        $this->assertSame(Terms::VERSION, $booking->terms_version);
    }

    /** L'aller et son retour sont deux lignes : le dossier du retour remonte à l'aller. */
    public function test_the_admin_file_shows_the_acceptance_even_on_the_return_leg(): void
    {
        $number = $this->postJson('/api/v1/public/bookings', $this->reservation([
            'round_trip' => true, 'return_time' => '17:00',
        ]))->assertStatus(201)->json('booking_number');
        $id = Booking::where('booking_number', $number)->value('id');
        $return = Booking::where('parent_booking_id', $id)->firstOrFail();

        $admin = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'view-bookings', 'guard_name' => 'web']));
        $token = $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        foreach ([$id, $return->id] as $bookingId) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson("/api/v1/admin/bookings/{$bookingId}")
                ->assertOk()
                ->assertJsonPath('terms_version', Terms::VERSION)
                ->assertJsonPath('terms_accepted_at', fn ($value) => is_string($value));
        }
    }

    /** Une course saisie par l'administration n'a pas de case à cocher : le dossier le dit, sans rien inventer. */
    public function test_a_booking_without_acceptance_says_so(): void
    {
        $booking = Booking::factory()->create();

        $admin = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        $admin->givePermissionTo(Permission::firstOrCreate(['name' => 'view-bookings', 'guard_name' => 'web']));
        $token = $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        $detail = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/admin/bookings/{$booking->id}")
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('terms_accepted_at', $detail);
        $this->assertNull($detail['terms_accepted_at']);
        $this->assertNull($detail['terms_version']);
    }
}
