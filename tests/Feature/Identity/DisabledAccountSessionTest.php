<?php

namespace Tests\Feature\Identity;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Un compte désactivé perd l'accès IMMÉDIATEMENT, quel que soit le chemin.
 *
 * ⚠️ Jusqu'au 2026-09-26, seule la CONNEXION vérifiait `is_active`. Un jeton déjà émis
 * restait valide jusqu'à son expiration — 14 jours d'inactivité, 90 au plus —, et une
 * session Blade jusqu'à sa fin : désactiver le compte d'un agent parti ou d'un téléphone
 * volé ne coupait rien. La révocation des jetons au moment de la désactivation ne
 * couvrait que les écrans qui y pensaient ; le contrôle vit désormais dans
 * l'authentification elle-même, pour tous les profils et tous les écrans.
 */
class DisabledAccountSessionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: string} */
    private function login(Profil $profil): array
    {
        $user = User::factory()->profil($profil)->create([
            'password' => Hash::make('bon-mot-de-passe'),
            'is_active' => true,
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');

        return [$user, $token];
    }

    private function asBearer(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_a_token_stops_working_as_soon_as_its_account_is_disabled(): void
    {
        foreach ([Profil::Driver, Profil::Owner, Profil::Admin, Profil::Client] as $profil) {
            [$user, $token] = $this->login($profil);

            $this->asBearer($token)->getJson('/api/v1/auth/me')->assertOk();

            // Désactivé par n'importe quel chemin — ici directement en base, comme le
            // ferait le Blade ou une commande.
            $user->forceFill(['is_active' => false])->save();

            $this->asBearer($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        }
    }

    public function test_reactivating_the_account_restores_its_tokens(): void
    {
        // Le jeton refusé n'est pas effacé : réactiver le compte le rend de nouveau
        // valide. C'est voulu — une désactivation temporaire ne force pas une
        // reconnexion —, et ce test le documente.
        [$user, $token] = $this->login(Profil::Driver);
        $user->forceFill(['is_active' => false])->save();
        $this->asBearer($token)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $user->forceFill(['is_active' => true])->save();

        $this->asBearer($token)->getJson('/api/v1/auth/me')->assertOk();
    }
}
