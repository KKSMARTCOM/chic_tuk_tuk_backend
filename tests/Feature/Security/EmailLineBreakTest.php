<?php

namespace Tests\Feature\Security;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Une adresse e-mail ne porte jamais de retour à la ligne (2026-09-29).
 *
 * La règle `email` de Laravel 11 en laisse passer (GHSA-5vg9-5847-vvmq) : une adresse
 * « a@b.bj\r\nBcc: … » enregistrée injecterait des en-têtes dans les e-mails qu'on lui
 * envoie, dont celui de réinitialisation du mot de passe. Laravel 11 ne recevra pas de
 * correctif ; `App\Shared\Validation\EmailRules` le neutralise en attendant la montée en
 * version.
 */
class EmailLineBreakTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Une adresse « repliée » : le retour à la ligne est dans une partie entre guillemets.
     * C'est la forme que la règle `email` accepte — une ligne brisée au milieu de
     * l'adresse, elle, était déjà refusée.
     */
    private const INJECTED = "\"awa\r\n \"@chictuktuk.bj";

    /** Même faille, dans un commentaire de l'adresse. */
    private const INJECTED_COMMENT = "awa(\r\n )@chictuktuk.bj";

    public function test_la_creation_d_un_administrateur_refuse_un_retour_a_la_ligne(): void
    {
        $admin = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);
        $admin->givePermissionTo(Permission::findOrCreate('create-users', 'web'));
        $token = $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => 'bon-mot-de-passe'])->json('token');
        Auth::forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/admin/users', [
                'name' => 'Awa',
                'email' => self::INJECTED,
                'phone' => '0197000001',
                'password' => 'MotDePasse2026!',
                'password_confirmation' => 'MotDePasse2026!',
                'roles' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_le_mot_de_passe_oublie_refuse_un_retour_a_la_ligne(): void
    {
        foreach ([self::INJECTED, self::INJECTED_COMMENT] as $email) {
            $this->postJson('/api/v1/auth/password/forgot', ['email' => $email])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['email']);
        }
    }

    public function test_une_adresse_normale_passe_toujours(): void
    {
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'awa@chictuktuk.bj'])
            ->assertOk();
    }
}
