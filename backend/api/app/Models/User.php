<?php

namespace App\Models;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Compte utilisateur global : un même compte peut appartenir à plusieurs institutions.
 * Les rôles et permissions sont toujours évalués dans l'institution active.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $fillable = ['name', 'email', 'phone', 'password', 'preferred_language', 'status'];

    protected $hidden = ['password', 'remember_token'];

    /** @var array<string, list<string>> cache des permissions par institution */
    private array $permissionCache = [];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }

    public function institutions(): BelongsToMany
    {
        return $this->belongsToMany(Institution::class, 'institution_user_memberships')
            ->using(InstitutionUserMembership::class)
            ->withPivot(['id', 'status', 'joined_at'])
            ->withTimestamps();
    }

    public function organizationUnits(): BelongsToMany
    {
        return $this->belongsToMany(OrganizationUnit::class, 'user_unit_memberships')
            ->using(UserUnitMembership::class)
            ->withPivot(['id', 'institution_id', 'membership_type', 'is_primary', 'started_at', 'ended_at'])
            ->withTimestamps();
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isMemberOf(Institution $institution): bool
    {
        return app(TenantContext::class)->runAs($institution, fn () => InstitutionUserMembership::query()
            ->where('user_id', $this->id)
            ->where('status', 'active')
            ->exists());
    }

    /**
     * Codes des permissions détenues dans l'institution active, toutes structures confondues.
     *
     * @return list<string>
     */
    public function permissionCodes(): array
    {
        $institutionId = app(TenantContext::class)->id();

        if ($institutionId === null) {
            return [];
        }

        return $this->permissionCache[$institutionId] ??= Permission::query()
            ->join('role_permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->join('user_roles', 'user_roles.role_id', '=', 'role_permissions.role_id')
            ->where('user_roles.user_id', $this->id)
            ->where('user_roles.institution_id', $institutionId)
            ->distinct()
            ->pluck('permissions.code')
            ->all();
    }

    public function hasPermission(string $code): bool
    {
        return in_array($code, $this->permissionCodes(), true);
    }

    /**
     * Vrai si la permission est détenue à l'échelle de l'institution, ou sur la structure
     * visée ou l'une de ses structures parentes.
     */
    public function hasPermissionForUnit(string $code, OrganizationUnit $unit): bool
    {
        $unitIds = UserRole::query()
            ->where('user_id', $this->id)
            ->whereHas('role.permissions', fn ($q) => $q->where('code', $code))
            ->pluck('organization_unit_id');

        if ($unitIds->contains(null)) {
            return true;
        }

        return OrganizationUnit::query()
            ->whereIn('id', $unitIds->filter()->all())
            ->get()
            ->contains(fn (OrganizationUnit $scope) => $scope->isSelfOrAncestorOf($unit));
    }

    public function flushPermissionCache(): void
    {
        $this->permissionCache = [];
    }
}
