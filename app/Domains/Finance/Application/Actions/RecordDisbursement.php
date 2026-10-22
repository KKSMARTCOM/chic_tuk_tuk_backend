<?php

namespace App\Domains\Finance\Application\Actions;

use App\Models\OwnerDisbursement;
use App\Models\User;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Enregistrer un versement au propriétaire (spec 2026-10-22-versement-proprietaire, §4.1) : dans
 * la limite de son dû, calculé et écrit sous verrou du contrat — deux saisies simultanées ne
 * dépassent pas le dû à deux.
 */
final class RecordDisbursement
{
    public function __construct(private readonly BuildDisbursementSummary $summary) {}

    /** @param  array{paid_on: string, amount: float|int|string, method: string, reference?: ?string, note?: ?string}  $data */
    public function __invoke(VehicleContract $contract, array $data, ?UploadedFile $proof, User $actor): OwnerDisbursement
    {
        $path = null;
        try {
            return DB::transaction(function () use ($contract, $data, $proof, $actor, &$path) {
                VehicleContract::query()->lockForUpdate()->findOrFail($contract->id);
                self::assertWithinOutstanding(($this->summary)([$contract->id])->outstanding, (float) $data['amount']);

                $path = $proof?->store("disbursements/{$contract->id}", 'local');

                return OwnerDisbursement::create([
                    'vehicle_contract_id' => $contract->id,
                    'paid_on' => $data['paid_on'],
                    'amount' => $data['amount'],
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'note' => $data['note'] ?? null,
                    'status' => 'recorded',
                    'created_by' => $actor->id,
                    ...self::proofColumns($proof, $path),
                ]);
            });
        } catch (\Throwable $e) {
            // L'écriture a échoué : le fichier rangé ne serait désigné par rien.
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }

    /** Le refus du plafond (§4.1), avec le restant pour que l'écran l'affiche. */
    public static function assertWithinOutstanding(float $outstanding, float $amount): void
    {
        if ($amount - $outstanding >= 0.005) {
            throw new ApiException(422, 'DISBURSEMENT_EXCEEDS_OUTSTANDING',
                'Le versement dépasse ce qui reste dû au propriétaire ('.number_format(max(0, $outstanding), 0, ',', ' ').' F).',
                ['outstanding' => round($outstanding, 2)]);
        }
    }

    /** @return array<string, mixed> */
    public static function proofColumns(?UploadedFile $proof, ?string $path): array
    {
        return $proof && $path ? [
            'proof_path' => $path,
            'proof_original_name' => mb_substr($proof->getClientOriginalName(), 0, 255),
            'proof_mime_type' => (string) $proof->getMimeType(),
            'proof_size' => (int) $proof->getSize(),
        ] : [];
    }
}
