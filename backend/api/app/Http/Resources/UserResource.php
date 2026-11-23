<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'preferred_language' => $this->preferred_language,
            'status' => $this->status,
            'last_login_at' => $this->last_login_at?->toIso8601ZuluString(),
            'units' => $this->whenLoaded('organizationUnits', fn () => $this->organizationUnits->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'membership_type' => $u->pivot->membership_type,
                'is_primary' => (bool) $u->pivot->is_primary,
            ])),
            'roles' => $this->whenLoaded('roleAssignments', fn () => $this->roleAssignments->map(fn ($a) => [
                'assignment_id' => $a->id,
                'code' => $a->role->code,
                'name' => __('roles.'.$a->role->code) !== 'roles.'.$a->role->code ? __('roles.'.$a->role->code) : $a->role->name,
                'organization_unit_id' => $a->organization_unit_id,
            ])),
        ];
    }
}
