<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Notification interne (table notifications, §Domaine G). */
class AppNotification extends Model
{
    use BelongsToInstitution, HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'notifications';

    protected $fillable = ['institution_id', 'user_id', 'notification_type', 'title', 'message', 'related_entity_type', 'related_entity_id', 'data', 'read_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime'];
    }
}
