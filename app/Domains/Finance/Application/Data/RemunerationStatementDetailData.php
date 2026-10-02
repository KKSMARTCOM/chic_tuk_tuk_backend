<?php

namespace App\Domains\Finance\Application\Data;

use App\Domains\Finance\Domain\Enums\RemunerationStatementStatus;
use App\Domains\Finance\Domain\StatementFigures;
use App\Models\RemunerationStatement;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScriptType;

/**
 * Le détail d'une fiche de rémunération, pour sa relecture.
 *
 * `blocking` annonce AVANT le clic ce que la validation refuserait : l'écran ne propose
 * jamais ce que l'API refusera.
 */
final class RemunerationStatementDetailData extends BaseData
{
    public function __construct(
        public string $id,
        public ?string $number,
        #[TypeScriptType(RemunerationStatementStatus::class)]
        public string $status,
        public string $statusLabel,
        /** `Y-m` */
        public string $month,
        public ?string $note,
        public bool $isFirstStatement,
        public bool $canValidate,
        /** @var string[] */
        public array $blocking,
        public StatementFiguresData $figures,
        public RemunerationStatementOpeningData $opening,
        public ?string $validatedAt,
        public ?string $validatedByName,
        public ?string $sentAt,
        public ?string $cancelledAt,
        public ?string $cancelReason,
        public ?string $replacesId,
        public ?string $replacedById,
        public bool $hasPdf,
        /** La date d'établissement (2026-10-01). */
        public ?string $issuedOn = null,
        #[LiteralTypeScriptType("'email' | 'none'")]
        public string $delivery = 'email',
        #[LiteralTypeScriptType("'ready' | 'preparing' | 'expired'")]
        public string $pdfState = 'preparing',
        public int $ownerDownloadCount = 0,
        /** L'adresse ACTUELLE du propriétaire, celle où partirait un envoi (2026-10-02). */
        public ?string $ownerEmail = null,
    ) {}

    /** @param  list<string>  $blocking */
    public static function fromStatement(RemunerationStatement $statement, StatementFigures $figures, array $blocking): self
    {
        $status = RemunerationStatementStatus::from($statement->status);

        return new self(
            id: $statement->id,
            number: $statement->number,
            status: $status->value,
            statusLabel: $status->label(),
            month: $statement->monthKey(),
            note: $statement->note,
            isFirstStatement: $figures->isFirstStatement,
            canValidate: $status === RemunerationStatementStatus::Draft && $blocking === [],
            blocking: array_values($blocking),
            figures: StatementFiguresData::fromFigures($figures),
            opening: new RemunerationStatementOpeningData(
                internet: (float) $statement->opening_internet,
                spotify: (float) $statement->opening_spotify,
                manager: (float) $statement->opening_manager,
            ),
            validatedAt: $statement->validated_at?->toIso8601String(),
            validatedByName: $statement->validator?->name,
            sentAt: $statement->sent_at?->toIso8601String(),
            cancelledAt: $statement->cancelled_at?->toIso8601String(),
            cancelReason: $statement->cancel_reason,
            replacesId: $statement->replaces_id,
            replacedById: RemunerationStatement::query()->where('replaces_id', $statement->id)->value('id'),
            hasPdf: $statement->pdf_path !== null,
            issuedOn: $statement->issued_on?->toDateString(),
            delivery: (string) ($statement->delivery ?? 'email'),
            pdfState: $statement->pdfState(),
            ownerDownloadCount: (int) $statement->owner_download_count,
            ownerEmail: $statement->contract?->vehicle?->owner?->email,
        );
    }
}
