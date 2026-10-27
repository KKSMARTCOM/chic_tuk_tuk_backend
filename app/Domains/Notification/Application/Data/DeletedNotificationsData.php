<?php

namespace App\Domains\Notification\Application\Data;

use App\Shared\Data\BaseData;

/** La réponse de `DELETE /notifications/read` : combien de notifications lues sont parties. */
final class DeletedNotificationsData extends BaseData
{
    public function __construct(
        public int $deleted,
    ) {}
}
