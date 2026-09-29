<?php

namespace App\Domains\Identity\Application\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetLinksMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * Un lien par compte portant l'adresse, étiqueté par le libellé du profil.
     * Plusieurs liens dans un seul message plutôt qu'un message par compte : le
     * destinataire est la même personne, et deux emails identiques à une étiquette
     * près prêtent à confusion.
     *
     * @param  list<array{label: string, url: string}>  $links
     */
    public function __construct(public array $links) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Réinitialisation de votre mot de passe ChicTukTuk');
    }

    public function content(): Content
    {
        // La version texte accompagne le HTML : certaines messageries n'affichent qu'elle,
        // et son absence fait monter le score de spam.
        return new Content(
            view: 'emails.password-reset-links',
            text: 'emails.password-reset-links-text',
        );
    }
}
