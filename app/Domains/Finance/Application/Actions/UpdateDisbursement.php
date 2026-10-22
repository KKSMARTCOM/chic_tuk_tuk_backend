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
 * Corriger un versement (spec 2026-10-22-versement-proprietaire, §4.3) : tous ses champs, preuve
 * comprise, dans la limite du dû augmenté de son ancien montant. Un versement annulé ne se
 * corrige pas.
 */
final class UpdateDisbursement
{
    private const FIELDS = ['paid_on', 'amount', 'method', 'reference', 'note'];

    public function __construct(private readonly BuildDisbursementSummary $summary) {}

    /**
     * @param  array<string, mixed>  $data  les seuls champs envoyés
     * @return array<string, array{from: mixed, to: mixed}> les champs changés, pour le journal
     */
    public function __invoke(OwnerDisbursement $disbursement, array $data, ?UploadedFile $proof, bool $removeProof, User $actor): array
    {
        $path = null;
        $oldProof = null;
        try {
            $changes = DB::transaction(function () use ($disbursement, $data, $proof, $removeProof, $actor, &$path, &$oldProof) {
                VehicleContract::query()->lockForUpdate()->findOrFail($disbursement->vehicle_contract_id);
                $disbursement = OwnerDisbursement::query()->lockForUpdate()->findOrFail($disbursement->id);
                self::assertRecorded($disbursement);

                $before = self::snapshot($disbursement);
                $disbursement->fill(array_intersect_key($data, array_flip(self::FIELDS)));
                if (array_key_exists('amount', $data)) {
                    $outstanding = ($this->summary)([$disbursement->vehicle_contract_id])->outstanding + (float) $before['amount'];
                    RecordDisbursement::assertWithinOutstanding($outstanding, (float) $data['amount']);
                }

                if ($proof || $removeProof) {
                    $oldProof = $disbursement->proof_path;
                    $path = $proof?->store("disbursements/{$disbursement->vehicle_contract_id}", 'local');
                    $disbursement->fill(RecordDisbursement::proofColumns($proof, $path) ?: [
                        'proof_path' => null, 'proof_original_name' => null, 'proof_mime_type' => null, 'proof_size' => null,
                    ]);
                }
                $disbursement->updated_by = $actor->id;
                $disbursement->save();

                $after = self::snapshot($disbursement);
                $changes = [];
                foreach ($after as $key => $value) {
                    if ($before[$key] !== $value) {
                        $changes[$key] = ['from' => $before[$key], 'to' => $value];
                    }
                }

                return $changes;
            });
        } catch (\Throwable $e) {
            // L'écriture a échoué : le fichier rangé ne serait désigné par rien.
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        // L'ancienne preuve ne part qu'une fois la correction enregistrée.
        if ($oldProof) {
            Storage::disk('local')->delete($oldProof);
        }

        return $changes;
    }

    public static function assertRecorded(OwnerDisbursement $disbursement): void
    {
        if ($disbursement->status !== 'recorded') {
            throw new ApiException(409, 'DISBURSEMENT_CANCELLED', 'Ce versement est annulé : il ne se corrige plus.');
        }
    }

    /** @return array<string, mixed> les champs comparés, sous une forme stable (date, montant à deux décimales) */
    private static function snapshot(OwnerDisbursement $d): array
    {
        return [
            'paid_on' => $d->paid_on?->toDateString(),
            'amount' => number_format((float) $d->amount, 2, '.', ''),
            'method' => $d->method,
            'reference' => $d->reference,
            'note' => $d->note,
            'proof' => $d->proof_original_name,
        ];
    }
}
