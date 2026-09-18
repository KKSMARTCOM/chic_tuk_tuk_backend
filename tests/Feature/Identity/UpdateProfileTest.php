<?php

namespace Tests\Feature\Identity;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PATCH /auth/profile — nom, téléphone, adresse.
 *
 * L'endpoint vit sous `auth` et non sous un espace : c'est l'utilisateur qu'on modifie,
 * pas l'agent, et le propriétaire comme l'administrateur en auront besoin.
 */
class UpdateProfileTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: string} */
    private function connecte(Profil $profil = Profil::Driver): array
    {
        $user = User::factory()->profil($profil)->create([
            'password' => Hash::make('bon-mot-de-passe'),
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');

        return [$user, $token];
    }

    public function test_sans_jeton_la_reponse_est_un_401(): void
    {
        $this->patchJson('/api/v1/auth/profile', ['name' => 'X', 'phone' => '+22997000001'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_l_agent_met_a_jour_son_nom_son_telephone_et_son_adresse(): void
    {
        [$user, $token] = $this->connecte();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/auth/profile', [
                'name' => 'Awa Dossou',
                'phone' => '+22997112233',
                'adresse' => 'Cadjehoun, Cotonou',
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Awa Dossou')
            ->assertJsonPath('phone', '+22997112233')
            ->assertJsonPath('adresse', 'Cadjehoun, Cotonou');

        $user->refresh();
        $this->assertSame('Awa Dossou', $user->name);
        $this->assertSame('+22997112233', $user->phone);
    }

    public function test_l_endpoint_sert_tous_les_profils_et_pas_seulement_l_agent(): void
    {
        // C'est la raison de le placer sous `auth` : un propriétaire doit pouvoir
        // corriger son téléphone sans qu'on duplique l'endpoint par espace.
        [, $token] = $this->connecte(Profil::Owner);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/auth/profile', ['name' => 'Kofi', 'phone' => '+22997445566'])
            ->assertOk()
            ->assertJsonPath('name', 'Kofi');
    }

    public function test_l_email_n_est_pas_modifiable(): void
    {
        // Décision de conception : l'e-mail est l'identifiant de connexion, et le
        // changer sans vérification par lien enfermerait dehors sur une faute de frappe.
        [$user, $token] = $this->connecte();
        $emailDOrigine = $user->email;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/auth/profile', [
                'name' => 'Awa',
                'phone' => '+22997778899',
                'email' => 'nouvelle@adresse.bj',
            ])
            ->assertOk();

        $this->assertSame($emailDOrigine, $user->fresh()->email);
    }

    public function test_un_telephone_deja_pris_est_refuse_en_422_et_non_en_500(): void
    {
        // `users.phone` est UNIQUE : sans la règle de validation, PostgreSQL lèverait
        // et le conflit ressortirait en 500 au lieu de désigner le champ.
        User::factory()->create(['phone' => '+22997000042']);
        [, $token] = $this->connecte();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/auth/profile', ['name' => 'Awa', 'phone' => '+22997000042'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['errors' => ['phone']]);
    }

    public function test_garder_son_propre_telephone_n_est_pas_un_conflit(): void
    {
        // Le garde-fou de la règle d'unicité : sans `ignore($id)`, personne ne pourrait
        // changer son nom sans changer aussi son numéro.
        [$user, $token] = $this->connecte();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/auth/profile', ['name' => 'Nouveau nom', 'phone' => $user->phone])
            ->assertOk()
            ->assertJsonPath('name', 'Nouveau nom');
    }

    public function test_le_nom_et_le_telephone_sont_obligatoires(): void
    {
        [, $token] = $this->connecte();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/auth/profile', [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['name', 'phone']]);
    }

    public function test_me_renvoie_desormais_le_telephone_et_l_adresse(): void
    {
        // `/auth/me` EST la lecture du profil : un endpoint de lecture de plus
        // renverrait le même utilisateur sous un autre nom.
        [, $token] = $this->connecte();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonStructure(['phone', 'adresse']);
    }
}
