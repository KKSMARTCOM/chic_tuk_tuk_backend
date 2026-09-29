<?php

namespace Tests\Feature\Identity;

use App\Domains\Identity\Application\Mail\PasswordResetLinksMail;
use Tests\TestCase;

/**
 * L'e-mail de réinitialisation se rend, en HTML comme en texte (mise en forme du
 * 2026-09-29). Mail::fake() ne rend rien : sans ce test, une erreur de gabarit ne se
 * verrait qu'à l'envoi réel.
 */
class PasswordResetMailRenderingTest extends TestCase
{
    private const URL = 'https://app-staging.chictuktuk.com/reset-password?token=abc.def';

    public function test_un_compte_un_bouton_et_son_lien_en_clair(): void
    {
        $mail = new PasswordResetLinksMail([['label' => 'Agent', 'url' => self::URL]]);

        $mail->assertSeeInHtml('Réinitialiser mon accès Agent');
        // Le bouton, puis le lien répété en clair pour les messageries qui cachent le bouton.
        $this->assertSame(2, substr_count($mail->render(), 'href="'.e(self::URL).'"'));
        $mail->assertSeeInHtml('Ce lien est valable');
        $mail->assertSeeInText(self::URL);
        $mail->assertSeeInText('Réinitialiser mon accès Agent');
    }

    public function test_plusieurs_comptes_un_bouton_chacun(): void
    {
        $mail = new PasswordResetLinksMail([
            ['label' => 'Administrateur', 'url' => self::URL.'1'],
            ['label' => 'Propriétaire', 'url' => self::URL.'2'],
        ]);

        $mail->assertSeeInHtml('Plusieurs comptes ChicTukTuk utilisent cette adresse');
        $mail->assertSeeInHtml('Réinitialiser mon accès Administrateur');
        $mail->assertSeeInHtml('Réinitialiser mon accès Propriétaire');
        $mail->assertSeeInHtml('Ces liens sont valables');
    }
}
