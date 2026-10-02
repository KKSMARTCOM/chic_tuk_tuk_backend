<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Jobs\IssueRemunerationStatement;
use App\Models\RemunerationStatement;
use App\Shared\Http\ApiException;

/**
 * Envoyer une fiche validée sans envoi, ou renvoyer une fiche envoyée qu'un propriétaire
 * redemande (2026-10-02). Rien n'est recalculé : la tâche d'envoi reprend le PDF rangé, et
 * les chiffres restent figés.
 */
final class SendRemunerationStatement
{
    public function __invoke(RemunerationStatement $statement): RemunerationStatement
    {
        if (! RemunerationStatement::visibleToOwners()) {
            throw new ApiException(409, 'STATEMENTS_HIDDEN_FROM_OWNERS', 'Les fiches sont cachées aux propriétaires le temps de la reconstitution : aucune ne part.');
        }
        $statement->refresh();
        if ($statement->status !== 'validated') {
            throw new ApiException(409, 'STATEMENT_NOT_VALIDATED', 'Seule une fiche validée s\'envoie.');
        }
        // Effacé au bout d'un an : la tâche ne le refait pas, il faut le régénérer d'abord.
        if ($statement->pdf_purged_at !== null) {
            throw new ApiException(409, 'STATEMENT_PDF_EXPIRED', 'Le PDF a été effacé au bout d\'un an : régénérez-le avant de l\'envoyer.');
        }

        // ⚠️ `sent_at` s'efface : la tâche ne renvoie jamais une fiche déjà envoyée.
        $statement->update(['delivery' => 'email', 'sent_at' => null]);
        IssueRemunerationStatement::dispatch($statement->id);

        return $statement;
    }
}
