<?php

namespace Tests\Feature\Booking;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\Booking;
use App\Models\Driver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DriverApiGuardTest extends TestCase
{
    use RefreshDatabase;

    /** Connecte un agent par l'API et renvoie [$driver, $token]. */
    private function loginDriver(array $permissions = ['view-bookings', 'edit-bookings']): array
    {
        [$user, $token] = $this->loginAs(Profil::Driver, $permissions);
        $driver = Driver::factory()->create(['user_id' => $user->id]);

        return [$driver, $token];
    }

    /** @return array{0: User, 1: string} */
    private function loginAs(Profil $profil, array $permissions = []): array
    {
        $user = User::factory()->profil($profil)->create([
            'password' => Hash::make('bon-mot-de-passe'),
        ]);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');

        return [$user, $token];
    }

    public function test_sans_jeton_la_reponse_est_un_401_json(): void
    {
        // Et surtout PAS une 302 vers /login, que l'ancienne branche Blade de
        // CheckPermission renvoyait (retirée le 2026-09-27).
        $this->getJson('/api/v1/driver/bookings/available')
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_un_jeton_d_un_autre_profil_est_refuse(): void
    {
        [, $token] = $this->loginAs(Profil::Admin, ['view-bookings', 'edit-bookings']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/driver/bookings/available')
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_un_agent_sans_view_bookings_est_refuse_sur_les_lectures(): void
    {
        [, $token] = $this->loginDriver(['edit-bookings']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/driver/bookings/available')
            ->assertStatus(403);
    }

    public function test_un_agent_sans_edit_bookings_est_refuse_sur_les_ecritures(): void
    {
        [, $token] = $this->loginDriver(['view-bookings']);
        $booking = Booking::factory()->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/driver/bookings/{$booking->id}/accept")
            ->assertStatus(403);
    }

    public function test_un_profil_driver_sans_ligne_drivers_donne_409_et_non_500(): void
    {
        [, $token] = $this->loginAs(Profil::Driver, ['view-bookings']);
        // Pas de Driver::factory() : c'est exactement l'incohérence visée.

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/driver/bookings/available')
            ->assertStatus(409)
            ->assertJsonPath('code', 'DRIVER_PROFILE_MISSING');
    }

    public function test_les_quatre_lectures_repondent_200_a_un_agent_en_regle(): void
    {
        // Ce test existe pour rendre les 403 et 404 ci-dessus discriminants : une route
        // absente répond 404 comme un refus de portée, et 403 comme rien du tout.
        [, $token] = $this->loginDriver();

        foreach ([
            '/api/v1/driver/bookings/available',
            '/api/v1/driver/bookings/assigned',
            '/api/v1/driver/bookings/history',
            '/api/v1/driver/dashboard',
        ] as $chemin) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson($chemin)
                ->assertOk();
        }
    }

    public function test_une_course_invisible_pour_cet_agent_donne_404(): void
    {
        [, $token] = $this->loginDriver();
        $titulaire = Driver::factory()->create();
        $parent = Booking::factory()
            ->subscriptionParent()
            ->linkedToSubscriptionDriver($titulaire)
            ->create();

        // D'abord prouver que la route répond sur une course accessible…
        $mienne = Booking::factory()->create();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/driver/bookings/{$mienne->id}/accept")
            ->assertOk();

        // …puis seulement, la 404 de portée.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/driver/bookings/{$parent->id}/accept")
            ->assertStatus(404)
            ->assertJsonPath('code', 'NOT_FOUND');
    }
}
