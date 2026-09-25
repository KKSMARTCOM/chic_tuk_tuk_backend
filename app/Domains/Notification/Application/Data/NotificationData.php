<?php

namespace App\Domains\Notification\Application\Data;

use App\Models\Notification;
use App\Shared\Data\BaseData;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;

/**
 * Une notification telle que la cloche l'affiche.
 *
 * ⚠️ `id` est un ENTIER, pas un uuid, contrairement à presque tout le reste du projet :
 * la table a été créée en janvier avec un `$table->id()` auto-incrémenté. On le laisse
 * tel quel — le changer imposerait une migration de données pour un bénéfice nul — mais
 * le front doit le typer en `number` et non en `string`.
 */
final class NotificationData extends BaseData
{
    public function __construct(
        public int $id,
        public string $title,
        public string $message,
        /** `info` | `success` | `warning` | `error` — pilote l'icône et la couleur. */
        #[LiteralTypeScriptType("'info' | 'success' | 'warning' | 'error'")]
        public string $type,
        public bool $isRead,
        /**
         * Charge libre déposée par l'émetteur. Le seul usage actuel est `url`, la
         * destination à ouvrir au clic.
         *
         * @var array<string, mixed>|null
         */
        // `unknown` et non `any` : le front doit vérifier ce qu'il lit dans une charge libre.
        #[LiteralTypeScriptType('Record<string, unknown> | null')]
        public ?array $data,
        public string $createdAt,
    ) {}

    public static function fromModel(Notification $notification): self
    {
        return new self(
            id: (int) $notification->id,
            title: $notification->title,
            message: $notification->message,
            type: $notification->type ?? 'info',
            isRead: (bool) $notification->is_read,
            data: $notification->data,
            createdAt: $notification->created_at->toIso8601String(),
        );
    }
}
