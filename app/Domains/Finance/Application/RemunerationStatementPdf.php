<?php

namespace App\Domains\Finance\Application;

use App\Domains\Finance\Domain\RemunerationBranding;
use App\Domains\Finance\Domain\StatementFigures;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Le PDF d'une fiche de rémunération, fidèle à la fiche manuelle d'origine (spec §5.6).
 *
 * Les images passent en `data:` URI : dompdf n'a aucun fichier à ouvrir hors de son
 * `chroot`, et le cachet, qui vit hors du dépôt, n'a pas à y être copié. `html()` existe
 * pour les tests : on vérifie le contenu sans décoder un PDF.
 */
final class RemunerationStatementPdf
{
    public function html(StatementFigures $figures, ?string $number, bool $draft, ?Carbon $issuedOn): string
    {
        $image = fn (?string $path) => $path ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path)) : null;

        return view('pdf.remuneration-statement', [
            'f' => $figures,
            'number' => $number,
            'draft' => $draft,
            'issuedOn' => $issuedOn ?? Carbon::now(),
            'monthLabel' => mb_strtoupper(Carbon::parse($figures->month.'-01')->locale('fr')->translatedFormat('F Y')),
            // ⚠️ Un brouillon n'est JAMAIS signé : un aperçu téléchargé serait sinon un
            // document au nom de la société, sur des chiffres qui peuvent encore changer.
            'signed' => ! $draft && RemunerationBranding::isComplete(),
            'stamp' => $draft ? null : $image(RemunerationBranding::stampPath()),
            'signature' => $draft ? null : $image(RemunerationBranding::signaturePath()),
            'logo' => $image(RemunerationBranding::logoPath()),
            'watermark' => $image(RemunerationBranding::watermarkPath()),
            'fonts' => resource_path('fonts'),
        ])->render();
    }

    public function render(StatementFigures $figures, ?string $number, bool $draft, ?Carbon $issuedOn): string
    {
        // dompdf met en cache les métriques de Montserrat : un dossier ignoré par git, créé
        // au besoin — `storage/fonts`, son défaut, n'existe ni en local ni dans l'image.
        $fontCache = storage_path('framework/cache/dompdf');
        File::ensureDirectoryExists($fontCache);

        return Pdf::loadHTML($this->html($figures, $number, $draft, $issuedOn))
            ->setPaper('a4')
            ->setOption([
                'chroot' => base_path(),
                'isRemoteEnabled' => false,
                'fontDir' => $fontCache,
                'fontCache' => $fontCache,
                // Seuls les glyphes utilisés : sans cela, les trois graisses entières pèsent
                // plus d'un mégaoctet par fiche envoyée.
                'isFontSubsettingEnabled' => true,
            ])
            ->output();
    }
}
