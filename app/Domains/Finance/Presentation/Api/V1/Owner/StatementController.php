<?php

namespace App\Domains\Finance\Presentation\Api\V1\Owner;

use App\Domains\Finance\Application\Data\OwnerStatementData;
use App\Models\RemunerationStatement;
use App\Models\Vehicle;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Les fiches de rémunération, côté propriétaire (spec 2026-09-30, §5.2).
 *
 * ⚠️ La portée passe par le VÉHICULE du propriétaire : la fiche d'autrui, comme un
 * brouillon ou une fiche annulée, est introuvable — 404, jamais 403, pour ne pas confirmer
 * au passant qu'elle existe.
 */
final class StatementController
{
    public function index(Request $request, string $id): JsonResponse
    {
        try {
            $vehicle = Vehicle::query()->where('owner_id', $request->user()->id)->findOrFail($id);

            return response()->json(RemunerationStatement::query()
                ->whereHas('contract', fn ($q) => $q->where('vehicle_id', $vehicle->id))
                ->where('status', 'validated')
                ->orderByDesc('month')
                ->get()
                ->map(fn (RemunerationStatement $s) => OwnerStatementData::fromModel($s)));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la liste des fiches de rémunération',
                'Les fiches de ce véhicule n\'ont pas pu être chargées. Réessayez.', 'OWNER_STATEMENTS_READ_FAILED');
        }
    }

    public function pdf(Request $request, string $id): Response|JsonResponse
    {
        try {
            $statement = RemunerationStatement::query()
                ->where('status', 'validated')
                ->whereHas('contract.vehicle', fn ($q) => $q->where('owner_id', $request->user()->id))
                ->findOrFail($id);

            // Effacé au bout d'un an : la fiche reste consultable, plus le fichier (2026-10-01).
            if ($statement->pdf_purged_at !== null) {
                throw new ApiException(410, 'STATEMENT_PDF_EXPIRED', 'Fichier indisponible.');
            }
            if ($statement->pdf_path === null || ! Storage::disk('local')->exists($statement->pdf_path)) {
                throw new ApiException(409, 'STATEMENT_NOT_READY', 'Votre fiche est en préparation. Réessayez dans quelques minutes.');
            }
            $bytes = Storage::disk('local')->get($statement->pdf_path);

            // Mise à jour CONDITIONNELLE : deux clics simultanés au 3e ne font pas un 4e.
            $counted = RemunerationStatement::query()->whereKey($statement->id)
                ->where('owner_download_count', '<', (int) config('remuneration.owner_download_limit'))
                ->increment('owner_download_count');
            if ($counted === 0) {
                // Une fiche reconstituée n'est jamais partie par e-mail : le propriétaire l'a reçue
                // sur papier (2026-10-01).
                throw new ApiException(403, 'STATEMENT_DOWNLOAD_LIMIT', $statement->delivery === 'none'
                    ? 'Limite de téléchargement atteinte.'
                    : 'Limite de téléchargement atteinte — la fiche vous a été envoyée par e-mail.');
            }

            return response($bytes, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="fiche-de-remuneration-'.$statement->number.'.pdf"',
            ]);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'le PDF d\'une fiche de rémunération',
                'Votre fiche n\'a pas pu être téléchargée. Réessayez.', 'OWNER_STATEMENT_PDF_FAILED');
        }
    }

    private function failure(\Throwable $e, Request $request, string $what, string $message, string $code): JsonResponse
    {
        Log::error("Erreur lors de {$what} : ".$e->getMessage(), [
            'exception' => $e,
            'user_id' => $request->user()?->id,
        ]);

        return response()->json(['message' => $message, 'code' => $code], 500);
    }
}
