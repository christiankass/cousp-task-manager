<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\InstitutionUserMembership;
use App\Models\OrganizationUnit;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Les comptes sont globaux, mais un administrateur ne voit et ne gère que les
 * membres de son institution.
 */
class UserController extends Controller
{
    public function __construct(private TenantContext $context, private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $users = $this->members()
            ->with(['organizationUnits', 'roleAssignments.role'])
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', '%'.$request->string('search').'%')
                ->orWhere('email', 'ilike', '%'.$request->string('search').'%')))
            ->when($request->filled('organization_unit_id'), fn ($q) => $q->whereHas('organizationUnits',
                fn ($u) => $u->where('organization_units.id', $request->string('organization_unit_id'))))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderBy('name')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return ApiResponse::success(UserResource::collection($users));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30'],
            'preferred_language' => ['nullable', Rule::in(['fr', 'en'])],
            'password' => ['nullable', Password::defaults()],
            'organization_unit_id' => ['nullable', 'uuid'],
            'role_code' => ['nullable', 'string', 'max:50'],
        ]);

        $email = Str::lower($data['email']);

        $user = DB::transaction(function () use ($data, $email) {
            $existing = User::query()->where('email', $email)->first();

            if ($existing && InstitutionUserMembership::query()->where('user_id', $existing->id)->exists()) {
                throw ValidationException::withMessages(['email' => [__('messages.user_already_member')]]);
            }

            // Un compte existant dans une autre institution est rattaché sans toucher à son mot de passe.
            $user = $existing ?? User::query()->create([
                'name' => $data['name'],
                'email' => $email,
                'phone' => $data['phone'] ?? null,
                'preferred_language' => $data['preferred_language'] ?? $this->context->institution()->default_language,
                'password' => $data['password'] ?? Str::password(24),
            ]);

            InstitutionUserMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);

            if (! empty($data['organization_unit_id'])) {
                $unit = $this->findUnit($data['organization_unit_id']);
                $user->organizationUnits()->attach($unit->id, [
                    'id' => (string) Str::uuid(),
                    'institution_id' => $this->context->id(),
                    'membership_type' => 'member',
                    'is_primary' => true,
                    'started_at' => now()->toDateString(),
                ]);
            }

            if (! empty($data['role_code'])) {
                $this->grantRole($user, $data['role_code'], null);
            }

            return $user;
        });

        $this->audit->log('user.added_to_institution', $user, [], ['email' => $user->email]);

        return ApiResponse::success(
            new UserResource($user->load(['organizationUnits', 'roleAssignments.role'])),
            __('messages.created'),
            201
        );
    }

    public function show(string $id): JsonResponse
    {
        $user = $this->members()->with(['organizationUnits', 'roleAssignments.role'])->findOrFail($id);

        return ApiResponse::success(new UserResource($user));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $user = $this->members()->findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'preferred_language' => ['sometimes', Rule::in(['fr', 'en'])],
        ]);

        $old = $user->only(array_keys($data));
        $user->update($data);
        $this->audit->log('user.updated', $user, $old, $user->only(array_keys($data)));

        return ApiResponse::success(new UserResource($user), __('messages.updated'));
    }

    /** Active ou suspend l'accès du compte à l'institution courante uniquement. */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $user = $this->members()->findOrFail($id);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])]]);

        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['status' => [__('messages.cannot_change_own_status')]]);
        }

        $membership = InstitutionUserMembership::query()->where('user_id', $user->id)->firstOrFail();
        $old = $membership->status;
        $membership->update(['status' => $data['status']]);

        if ($data['status'] === 'suspended') {
            $user->tokens()->where('institution_id', $this->context->id())->delete();
        }

        $this->audit->log('user.status_changed', $user, ['status' => $old], ['status' => $data['status']]);

        return ApiResponse::success(['status' => $data['status']], __('messages.updated'));
    }

    public function assignRole(Request $request, string $id): JsonResponse
    {
        $user = $this->members()->findOrFail($id);
        $data = $request->validate([
            'role_code' => ['required', 'string', 'max:50'],
            'organization_unit_id' => ['nullable', 'uuid'],
        ]);

        if ($data['role_code'] === 'institution_admin' && ! $request->user()->hasPermission('settings.manage')) {
            return ApiResponse::error(__('messages.forbidden'), 'FORBIDDEN', 403);
        }

        $unit = ! empty($data['organization_unit_id']) ? $this->findUnit($data['organization_unit_id']) : null;
        $assignment = $this->grantRole($user, $data['role_code'], $unit);

        return ApiResponse::success([
            'assignment_id' => $assignment->id,
            'role_code' => $data['role_code'],
            'organization_unit_id' => $unit?->id,
        ], __('messages.role_assigned'), 201);
    }

    public function removeRole(Request $request, string $id, string $assignmentId): JsonResponse
    {
        $user = $this->members()->findOrFail($id);
        $assignment = UserRole::query()->where('user_id', $user->id)->findOrFail($assignmentId);
        $assignment->delete();
        $this->audit->log('user.role_removed', $user, ['assignment_id' => $assignmentId, 'role_id' => $assignment->role_id]);

        return response()->json(null, 204);
    }

    /** Utilisateurs membres de l'institution active. */
    private function members(): Builder
    {
        return User::query()->whereIn('users.id', InstitutionUserMembership::query()->select('user_id'));
    }

    private function findUnit(string $id): OrganizationUnit
    {
        return OrganizationUnit::query()->find($id)
            ?? throw ValidationException::withMessages(['organization_unit_id' => [__('validation.exists', ['attribute' => 'organization_unit_id'])]]);
    }

    private function grantRole(User $user, string $code, ?OrganizationUnit $unit): UserRole
    {
        $role = Role::query()->where('code', $code)->orderByRaw('institution_id is null')->first()
            ?? throw ValidationException::withMessages(['role_code' => [__('validation.exists', ['attribute' => 'role_code'])]]);

        $assignment = UserRole::query()->firstOrCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_unit_id' => $unit?->id,
        ]);

        $this->audit->log('user.role_assigned', $user, [], ['role' => $code, 'organization_unit_id' => $unit?->id]);
        $user->flushPermissionCache();

        return $assignment;
    }
}
