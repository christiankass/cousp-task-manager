<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UnitType;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationUnitResource;
use App\Http\Resources\UserResource;
use App\Models\InstitutionUserMembership;
use App\Models\OrganizationUnit;
use App\Models\UserUnitMembership;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizationUnitController extends Controller
{
    public function __construct(private TenantContext $context, private AuditLogger $audit) {}

    /** Liste à plat (filtrable) ou arborescence complète avec ?tree=1. */
    public function index(Request $request): JsonResponse
    {
        $query = OrganizationUnit::query()->with('manager')->orderBy('name');

        if ($request->boolean('tree')) {
            $units = $query->get();
            $byParent = $units->groupBy(fn ($u) => $u->parent_id ?? 'root');
            $build = function (OrganizationUnit $unit) use (&$build, $byParent) {
                $unit->setRelation('children', ($byParent[$unit->id] ?? collect())->map($build)->values());

                return $unit;
            };

            return ApiResponse::success(OrganizationUnitResource::collection(
                ($byParent['root'] ?? collect())->map($build)->values()
            )->resolve($request));
        }

        $query
            ->when($request->filled('parent_id'), fn ($q) => $q->where('parent_id', $request->string('parent_id')))
            ->when($request->filled('unit_type'), fn ($q) => $q->where('unit_type', $request->string('unit_type')))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'ilike', '%'.$request->string('search').'%'));

        return ApiResponse::success(OrganizationUnitResource::collection(
            $query->paginate(min((int) $request->integer('per_page', 50), 100))
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $unit = OrganizationUnit::query()->create($data);
        $this->audit->log('organization_unit.created', $unit, [], $unit->only(array_keys($data)));

        return ApiResponse::success(new OrganizationUnitResource($unit->load('manager')), __('messages.created'), 201);
    }

    public function show(OrganizationUnit $organizationUnit): JsonResponse
    {
        return ApiResponse::success(new OrganizationUnitResource($organizationUnit->load(['manager', 'children'])));
    }

    public function update(Request $request, OrganizationUnit $organizationUnit): JsonResponse
    {
        $data = $this->validated($request, $organizationUnit);
        $old = $organizationUnit->only(array_keys($data));
        $organizationUnit->update($data);
        $this->audit->log('organization_unit.updated', $organizationUnit, $old, $organizationUnit->only(array_keys($data)));

        return ApiResponse::success(new OrganizationUnitResource($organizationUnit->load('manager')), __('messages.updated'));
    }

    /** Désactivation : les structures ne sont jamais supprimées physiquement. */
    public function destroy(OrganizationUnit $organizationUnit): JsonResponse
    {
        $organizationUnit->update(['is_active' => false]);
        $this->audit->log('organization_unit.deactivated', $organizationUnit);

        return ApiResponse::success(new OrganizationUnitResource($organizationUnit), __('messages.deactivated'));
    }

    public function members(Request $request, OrganizationUnit $organizationUnit): JsonResponse
    {
        return ApiResponse::success(UserResource::collection(
            $organizationUnit->members()->orderBy('name')->paginate(min((int) $request->integer('per_page', 50), 100))
        ));
    }

    public function addMember(Request $request, OrganizationUnit $organizationUnit): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'uuid'],
            'membership_type' => ['nullable', 'string', Rule::in(['member', 'manager', 'deputy'])],
            'is_primary' => ['nullable', 'boolean'],
            'started_at' => ['nullable', 'date'],
        ]);

        $this->ensureInstitutionMember($data['user_id'], 'user_id');

        $membership = UserUnitMembership::query()->updateOrCreate(
            ['organization_unit_id' => $organizationUnit->id, 'user_id' => $data['user_id']],
            [
                'membership_type' => $data['membership_type'] ?? 'member',
                'is_primary' => $data['is_primary'] ?? false,
                'started_at' => $data['started_at'] ?? now()->toDateString(),
            ]
        );

        if ($membership->is_primary) {
            UserUnitMembership::query()
                ->where('user_id', $data['user_id'])
                ->whereKeyNot($membership->id)
                ->update(['is_primary' => false]);
        }

        $this->audit->log('organization_unit.member_added', $organizationUnit, [], ['user_id' => $data['user_id']]);

        return ApiResponse::success(null, __('messages.member_added'), 201);
    }

    public function removeMember(OrganizationUnit $organizationUnit, string $userId): JsonResponse
    {
        UserUnitMembership::query()
            ->where('organization_unit_id', $organizationUnit->id)
            ->where('user_id', $userId)
            ->delete();
        $this->audit->log('organization_unit.member_removed', $organizationUnit, ['user_id' => $userId]);

        return response()->json(null, 204);
    }

    private function validated(Request $request, ?OrganizationUnit $unit = null): array
    {
        $required = $unit ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:200'],
            'code' => [$required, 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/'],
            'unit_type' => [$required, Rule::enum(UnitType::class)],
            'parent_id' => ['nullable', 'uuid'],
            'description' => ['nullable', 'string', 'max:5000'],
            'manager_id' => ['nullable', 'uuid'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['code'])) {
            $data['code'] = Str::upper($data['code']);
            $exists = OrganizationUnit::query()
                ->where('code', $data['code'])
                ->when($unit, fn ($q) => $q->whereKeyNot($unit->id))
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages(['code' => [__('validation.unique', ['attribute' => 'code'])]]);
            }
        }

        if (! empty($data['parent_id'])) {
            // Recherche cloisonnée : un parent d'une autre institution est introuvable.
            $parent = OrganizationUnit::query()->find($data['parent_id'])
                ?? throw ValidationException::withMessages(['parent_id' => [__('validation.exists', ['attribute' => 'parent'])]]);

            if ($unit && $unit->isSelfOrAncestorOf($parent)) {
                throw ValidationException::withMessages(['parent_id' => [__('messages.unit_cycle')]]);
            }
        }

        if (! empty($data['manager_id'])) {
            $this->ensureInstitutionMember($data['manager_id'], 'manager_id');
        }

        return $data;
    }

    private function ensureInstitutionMember(string $userId, string $field): void
    {
        $isMember = InstitutionUserMembership::query()
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->exists();

        if (! $isMember) {
            throw ValidationException::withMessages([$field => [__('messages.user_not_in_institution')]]);
        }
    }
}
