<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function __construct(private TenantContext $context) {}

    public function log(string $action, ?Model $entity = null, array $old = [], array $new = [], ?string $institutionId = null, ?User $actor = null): AuditLog
    {
        $request = request();
        $institutionId ??= $this->context->id() ?? ($entity?->getAttribute('institution_id'));

        $write = fn () => AuditLog::query()->create([
            'institution_id' => $institutionId,
            'user_id' => $actor?->id ?? $request?->user()?->id,
            'action' => $action,
            'entity_type' => $entity ? $entity->getMorphClass() : null,
            'entity_id' => $entity?->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);

        // Les journaux de plateforme (sans institution) s'écrivent hors cloisonnement.
        return $institutionId === null || $institutionId !== $this->context->id()
            ? $this->context->withoutIsolation($write)
            : $write();
    }
}
