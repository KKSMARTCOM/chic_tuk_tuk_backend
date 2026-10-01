<?php

namespace App\Domains\Finance\Application\Actions;

use App\Models\RemunerationStatement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Vider les fiches annulées (spec 2026-10-01, §7.1) : sans effet sur l'argent, l'annulation
 * a déjà détaché leurs paiements. Les numéros ne seront jamais réattribués : le compteur
 * `remuneration_statement_numbers` ne recule pas.
 */
final class PurgeCancelledStatements
{
    /** @return list<string> les numéros supprimés */
    public function __invoke(): array
    {
        $cancelled = RemunerationStatement::query()->where('status', 'cancelled')->get();
        if ($cancelled->isEmpty()) {
            return [];
        }

        DB::transaction(function () use ($cancelled) {
            $ids = $cancelled->pluck('id');
            RemunerationStatement::query()->whereIn('replaces_id', $ids)->update(['replaces_id' => null]);
            RemunerationStatement::query()->whereIn('id', $ids)->delete();
        });

        // Après la transaction : un fichier effacé pour une suppression annulée serait perdu.
        $cancelled->pluck('pdf_path')->filter()->each(fn ($path) => Storage::disk('local')->delete($path));

        return $cancelled->pluck('number')->filter()->values()->all();
    }
}
