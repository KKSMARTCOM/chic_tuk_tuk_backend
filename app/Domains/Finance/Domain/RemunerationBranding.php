<?php

namespace App\Domains\Finance\Domain;

/**
 * Les images de la fiche de rémunération : le logo et le filigrane, versionnés.
 *
 * ⚠️ Plus de cachet ni de signature depuis le 2026-10-05 : la fiche s'arrête à « Fait à
 * Cotonou, le … », et rien n'est lu dans `storage/app/private/branding`. Le logo a perdu
 * son monogramme « ka » le même jour.
 */
final class RemunerationBranding
{
    public static function logoPath(): string
    {
        return resource_path('pdf/remuneration-statement/logo.png');
    }

    public static function watermarkPath(): string
    {
        return resource_path('pdf/remuneration-statement/filigrane.png');
    }
}
