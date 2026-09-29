<?php

namespace App\Domains\Audit\Application\Data;

use App\Domains\Audit\Domain\ActivityEvent;
use App\Shared\Data\BaseData;
use Spatie\Activitylog\Models\Activity;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * Une ligne du journal d'activité.
 *
 * `actor` est l'auteur figé au moment de l'action (« Système » pour une tâche planifiée,
 * vide pour une connexion refusée, dont la phrase est complète). Le front affiche
 * « {actor} {description} ».
 *
 * `subjectType` est l'alias de la table polymorphe du projet (`booking`, `user`,
 * `payment`… — `AppServiceProvider::MORPH_MAP`, imposée), jamais la classe PHP : c'est lui
 * qui permet au front de mener à la fiche de l'objet.
 */
final class ActivityEntryData extends BaseData
{
    public function __construct(
        public int $id,
        public ?string $event,
        public ?string $eventLabel,
        public ?string $actor,
        public ?string $causerId,
        public string $description,
        public ?string $subjectType,
        public ?string $subjectId,
        public ?string $ip,
        /** @var array<string, array{from: mixed, to: mixed}>|null */
        #[LiteralTypeScriptType('Record<string, { from: unknown; to: unknown }> | null')]
        public ?array $changes,
        public string $createdAt,
    ) {}

    public static function fromModel(Activity $activity): self
    {
        $properties = $activity->properties;

        return new self(
            id: (int) $activity->id,
            event: $activity->event,
            eventLabel: ActivityEvent::tryFrom((string) $activity->event)?->label(),
            actor: $properties->get('actor'),
            causerId: $activity->causer_id,
            description: $activity->description,
            subjectType: $activity->subject_type,
            subjectId: $activity->subject_id,
            ip: $properties->get('ip'),
            changes: $properties->get('changes'),
            createdAt: $activity->created_at->toIso8601String(),
        );
    }
}
