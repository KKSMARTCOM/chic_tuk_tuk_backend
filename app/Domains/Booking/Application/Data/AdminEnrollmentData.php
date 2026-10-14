<?php

namespace App\Domains\Booking\Application\Data;

use App\Models\SubscriptionEnrollment;
use App\Shared\Data\BaseData;
use Carbon\CarbonInterface;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/** L'inscription sur la fiche admin d'une réservation (spec 2026-10-14, §5.2). L'admin seul la voit. */
final class AdminEnrollmentData extends BaseData
{
    public function __construct(
        public string $id,
        #[LiteralTypeScriptType("'missing' | 'submitted'")]
        public string $status,
        public string $url,
        public string $tokenExpiresAt,
        public bool $linkExpired,
        public ?string $submittedAt,
        public ?string $clientName,
        public ?string $whatsapp,
        public ?string $email,
        public ?string $homeAddress,
        #[LiteralTypeScriptType("'school' | 'work' | null")]
        public ?string $kind,
        public ?string $schoolName,
        public ?string $schoolContact,
        public ?string $workplace,
        public ?string $workHours,
        public ?string $pickupLandmark,
        public ?string $dropoffLandmark,
        #[LiteralTypeScriptType("'cni' | 'passport' | 'other' | null")]
        public ?string $idDocumentType,
        public ?string $idDocumentOther,
        public bool $hasIdDocument,
        public ?string $idDocumentUploadedAt,
        public ?string $idDocumentPurgedAt,
        public ?string $correctedAt,
        /** @var array<int, EnrollmentPersonData> */
        public array $people,
    ) {}

    public static function fromModel(SubscriptionEnrollment $e): self
    {
        $iso = fn (?CarbonInterface $at) => $at?->toIso8601String();

        return new self(
            id: $e->id,
            status: $e->submitted_at ? 'submitted' : 'missing',
            url: $e->url(),
            tokenExpiresAt: $e->token_expires_at->toIso8601String(),
            linkExpired: $e->token_expires_at->isPast(),
            submittedAt: $iso($e->submitted_at),
            clientName: $e->client_name,
            whatsapp: $e->whatsapp,
            email: $e->email,
            homeAddress: $e->home_address,
            kind: $e->kind,
            schoolName: $e->school_name,
            schoolContact: $e->school_contact,
            workplace: $e->workplace,
            workHours: $e->work_hours,
            pickupLandmark: $e->pickup_landmark,
            dropoffLandmark: $e->dropoff_landmark,
            idDocumentType: $e->id_document_type,
            idDocumentOther: $e->id_document_other,
            hasIdDocument: $e->id_document_path !== null,
            idDocumentUploadedAt: $iso($e->id_document_uploaded_at),
            idDocumentPurgedAt: $iso($e->id_document_purged_at),
            correctedAt: $iso($e->corrected_at),
            people: $e->people->map(fn ($p) => EnrollmentPersonData::fromModel($p))->all(),
        );
    }
}
