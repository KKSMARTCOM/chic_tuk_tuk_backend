<?php

namespace App\Domains\Finance\Domain;

/**
 * Les images de la fiche de rémunération.
 *
 * Le logo et le filigrane sont publics et versionnés. Le cachet et la signature ne le sont
 * PAS (spec §7) : un fichier manquant rend `null`, le PDF porte alors « SPÉCIMEN — non
 * signé », et la validation est refusée.
 */
final class RemunerationBranding
{
    public static function isComplete(): bool
    {
        return self::stampPath() !== null && self::signaturePath() !== null;
    }

    public static function stampPath(): ?string
    {
        return self::private('cachet.png');
    }

    public static function signaturePath(): ?string
    {
        return self::private('signature.png');
    }

    public static function logoPath(): string
    {
        return resource_path('pdf/remuneration-statement/logo.png');
    }

    public static function watermarkPath(): string
    {
        return resource_path('pdf/remuneration-statement/filigrane.png');
    }

    private static function private(string $file): ?string
    {
        $path = rtrim((string) config('remuneration.branding_dir'), '/').'/'.$file;

        return is_file($path) ? $path : null;
    }
}
