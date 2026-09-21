<?php

namespace Tests\Feature\Notification;

use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\FcmToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * L'enregistrement d'un appareil — et le défaut qui le rendait impossible.
 *
 * ⚠️ `fcm_tokens.id` est une colonne `uuid` NOT NULL sans valeur par défaut, mais le
 * modèle `FcmToken` ne portait PAS le trait `HasUuid` : Eloquent le croyait
 * auto-incrémenté, omettait la colonne à l'insertion, et PostgreSQL refusait la ligne.
 * `FcmController::store()` levait donc une `QueryException` à CHAQUE appel.
 *
 * Conséquence : aucun jeton n'a jamais pu être rangé, donc aucune notification push n'a
 * jamais eu de destinataire. Le seul déclencheur de l'application — une nouvelle
 * réservation prévient les agents — parcourait toujours une liste vide.
 *
 * Trouvé le 2026-09-21 en ouvrant le sous-lot 3c. Corrigé plutôt que transposé : c'est
 * la règle du projet sur les défauts avérés découverts en transposition.
 */
class FcmTokenStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_jeton_s_enregistre_et_recoit_un_uuid(): void
    {
        $user = User::factory()->profil(Profil::Driver)->create();

        $jeton = FcmToken::create(['user_id' => $user->id, 'token' => 'abc123']);

        $this->assertNotNull($jeton->id, 'aucun identifiant généré : le trait HasUuid manque');
        $this->assertTrue(Str::isUuid($jeton->id));
        $this->assertDatabaseHas('fcm_tokens', ['token' => 'abc123', 'user_id' => $user->id]);
    }

    public function test_le_meme_jeton_change_de_proprietaire_sans_doublon(): void
    {
        // Le cas réel : deux comptes se succèdent sur le même téléphone. `token` est
        // UNIQUE, donc la ligne doit être RÉATTRIBUÉE, jamais dupliquée — sinon
        // l'ancien propriétaire continuerait de recevoir les notifications du nouveau.
        [$premier, $second] = [
            User::factory()->profil(Profil::Driver)->create(),
            User::factory()->profil(Profil::Owner)->create(),
        ];

        FcmToken::updateOrCreate(['token' => 'meme-appareil'], ['user_id' => $premier->id]);
        FcmToken::updateOrCreate(['token' => 'meme-appareil'], ['user_id' => $second->id]);

        $this->assertSame(1, FcmToken::where('token', 'meme-appareil')->count());
        $this->assertSame($second->id, FcmToken::where('token', 'meme-appareil')->first()->user_id);
    }

    public function test_les_jetons_d_un_utilisateur_sont_atteignables_par_la_relation(): void
    {
        $user = User::factory()->profil(Profil::Driver)->create();
        FcmToken::create(['user_id' => $user->id, 'token' => 'un']);
        FcmToken::create(['user_id' => $user->id, 'token' => 'deux']);

        $this->assertCount(2, $user->fresh()->fcmTokens);
    }
}
