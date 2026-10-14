<?php

namespace App\Domains\Booking\Application\Data;

use App\Shared\Data\BaseData;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * POST /public/enrollments/{token} — les réponses (spec 2026-10-14, §5.1).
 * La pièce d'identité arrive à part (`id_document`), validée ici mais lue par le contrôleur.
 */
final class SubmitEnrollmentData extends BaseData
{
    public const COMMITMENTS = ['payment', 'notify_changes', 'cooperate', 'planned_service', 'absences_impact', 'monthly_due'];

    public const MAX_PEOPLE = 5;

    public function __construct(
        public string $clientName,
        public string $whatsapp,
        public ?string $email,
        public string $homeAddress,
        #[LiteralTypeScriptType("'school' | 'work'")]
        public string $kind,
        public ?string $schoolName,
        public ?string $schoolContact,
        public ?string $workplace,
        public ?string $workHours,
        public ?string $pickupLandmark,
        public ?string $dropoffLandmark,
        /** @var array<int, EnrollmentPersonInputData> */
        #[DataCollectionOf(EnrollmentPersonInputData::class)]
        public array $people,
        #[LiteralTypeScriptType("'cni' | 'passport' | 'other' | null")]
        public ?string $idDocumentType = null,
        public ?string $idDocumentOther = null,
        /** @var array<string, bool> */
        #[LiteralTypeScriptType('Record<string, boolean>')]
        public array $commitments = [],
    ) {}

    /** Les règles des réponses, partagées avec la correction par l'admin (tâche 5). */
    public static function answerRules(): array
    {
        return [
            'client_name' => ['required', 'string', 'max:255'],
            'whatsapp' => ['required', 'string', 'regex:/^[0-9+\-\s()]{8,20}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'home_address' => ['required', 'string', 'max:255'],
            'kind' => ['required', 'in:school,work'],
            'school_name' => ['nullable', 'required_if:kind,school', 'string', 'max:255'],
            'school_contact' => ['nullable', 'string', 'max:255'],
            'workplace' => ['nullable', 'string', 'max:255'],
            'work_hours' => ['nullable', 'string', 'max:255'],
            'pickup_landmark' => ['nullable', 'string', 'max:255'],
            'dropoff_landmark' => ['nullable', 'string', 'max:255'],
            'people' => ['required', 'array', 'min:1', 'max:'.self::MAX_PEOPLE],
            'people.*.full_name' => ['required', 'string', 'max:255'],
            'people.*.age' => ['nullable', 'required_if:kind,school', 'integer', 'min:1', 'max:25'],
            'people.*.school_class' => ['nullable', 'required_if:kind,school', 'string', 'max:100'],
            'people.*.health_notes' => ['nullable', 'required_if:kind,school', 'string', 'max:1000'],
            'people.*.profession' => ['nullable', 'string', 'max:255'],
            'id_document_type' => ['nullable', 'in:cni,passport,other'],
            'id_document_other' => ['nullable', 'required_if:id_document_type,other', 'string', 'max:100'],
        ];
    }

    public static function rules(): array
    {
        $commitments = [];
        foreach (self::COMMITMENTS as $key) {
            $commitments["commitments.{$key}"] = ['accepted'];
        }

        return [
            ...self::answerRules(),
            'commitments' => ['required', 'array'],
            ...$commitments,
            'id_document' => ['nullable', 'file', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:10240'],
            'id_document_type' => ['nullable', 'required_with:id_document', 'in:cni,passport,other'],
        ];
    }

    public static function messages(): array
    {
        $messages = [
            'client_name.required' => 'Votre nom et prénoms sont obligatoires.',
            'whatsapp.required' => 'Le numéro WhatsApp est obligatoire.',
            'whatsapp.regex' => 'Le numéro WhatsApp n\'est pas valide.',
            'email.email' => 'L\'adresse e-mail n\'est pas valide.',
            'home_address.required' => 'L\'adresse de résidence est obligatoire.',
            'kind.required' => 'Choisissez le type d\'abonnement.',
            'school_name.required_if' => 'L\'établissement scolaire est obligatoire.',
            'people.required' => 'Ajoutez au moins une personne.',
            'people.min' => 'Ajoutez au moins une personne.',
            'people.max' => 'Cinq personnes au plus par inscription.',
            'people.*.full_name.required' => 'Le nom et prénoms sont obligatoires.',
            'people.*.age.required_if' => 'L\'âge de l\'enfant est obligatoire.',
            'people.*.age.min' => 'L\'âge n\'est pas valide.',
            'people.*.age.max' => 'L\'âge n\'est pas valide.',
            'people.*.school_class.required_if' => 'La classe est obligatoire.',
            'people.*.health_notes.required_if' => 'Indiquez l\'état de santé (« RAS » si rien à signaler).',
            'id_document_other.required_if' => 'Précisez le type de pièce.',
            'id_document_type.required_with' => 'Indiquez le type de pièce d\'identité.',
            'id_document.mimetypes' => 'La pièce d\'identité doit être un PDF, un JPEG ou un PNG.',
            'id_document.max' => 'La pièce d\'identité ne doit pas dépasser 10 Mo.',
        ];
        foreach (self::COMMITMENTS as $key) {
            $messages["commitments.{$key}.accepted"] = 'Cochez cet engagement pour continuer.';
        }

        return $messages;
    }
}
