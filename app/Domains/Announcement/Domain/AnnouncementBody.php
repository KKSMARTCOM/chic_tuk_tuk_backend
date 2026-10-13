<?php

namespace App\Domains\Announcement\Domain;

use Illuminate\Support\Str;

/**
 * Le texte d'un communiqué : du Markdown, rendu en HTML ICI SEULEMENT (spec 2026-10-13, §3.4).
 *
 * ⚠️ `html_input: strip` supprime tout HTML brut et `allow_unsafe_links: false` refuse les
 * liens `javascript:`, `data:`… Le même HTML sert la page et l'e-mail : le front l'affiche
 * tel quel et ne convertit jamais rien lui-même. Relâcher l'une de ces options ouvre une
 * faille XSS chez tous les destinataires.
 */
final class AnnouncementBody
{
    public static function toHtml(string $markdown): string
    {
        return (string) Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
