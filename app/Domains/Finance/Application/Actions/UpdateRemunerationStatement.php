<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Data\UpdateRemunerationStatementData;
use App\Domains\Finance\Domain\ChargeLedger;
use App\Models\RemunerationStatement;
use App\Shared\Http\ApiException;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Optional;

/**
 * Enregistrer les saisies d'un brouillon. Les garde-fous du compte de charges sont vérifiés
 * ICI, et pas seulement à la validation : l'écran montre l'erreur sous le champ fautif.
 */
final class UpdateRemunerationStatement
{
    public function __construct(private readonly BuildStatementFigures $figures) {}

    public function __invoke(RemunerationStatement $statement, UpdateRemunerationStatementData $data): RemunerationStatement
    {
        if ($statement->status !== 'draft') {
            throw new ApiException(409, 'STATEMENT_NOT_DRAFT', 'Seul un brouillon se modifie. Une fiche validée s\'annule.');
        }

        $opening = array_filter([
            'opening_internet' => $data->openingInternet,
            'opening_spotify' => $data->openingSpotify,
            'opening_manager' => $data->openingManager,
        ], fn ($value) => $value !== null);

        $statement->fill([
            'deducted_internet' => $data->deductedInternet,
            'deducted_spotify' => $data->deductedSpotify,
            'deducted_manager' => $data->deductedManager,
            'note' => $data->note,
        ] + $opening);

        if (! $data->issuedOn instanceof Optional) {
            if ($data->issuedOn !== null) {
                $issueViolations = app(ValidateRemunerationStatement::class)->issueDateViolations($statement, Carbon::parse($data->issuedOn));
                if ($issueViolations !== []) {
                    throw ValidationException::withMessages($issueViolations);
                }
            }
            $statement->issued_on = $data->issuedOn;
        }

        $figures = ($this->figures)($statement->contract, $statement->monthKey(), $statement);

        if ($opening !== [] && ! $figures->isFirstStatement) {
            throw ValidationException::withMessages(['opening_manager' => 'Le reste d\'ouverture ne se saisit que sur la première fiche du contrat.']);
        }

        $violations = ChargeLedger::violations(
            collect($figures->charges)->pluck('deducted', 'key')->all(),
            collect($figures->charges)->pluck('outstanding', 'key')->all(),
            $figures->revenue + $figures->recovered,
        );
        if ($violations !== []) {
            throw ValidationException::withMessages($violations);
        }

        $statement->save();

        return $statement->refresh();
    }
}
