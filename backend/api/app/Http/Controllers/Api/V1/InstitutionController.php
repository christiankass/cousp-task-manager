<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InstitutionResource;
use App\Models\Institution;
use App\Models\InstitutionUserMembership;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Gestion des institutions : réservée au super administrateur de plateforme. */
class InstitutionController extends Controller
{
    public function __construct(private TenantContext $context, private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(InstitutionResource::collection(
            Institution::query()->orderBy('name')->paginate(min((int) $request->integer('per_page', 50), 100))
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $institution = DB::transaction(function () use ($data) {
            $institution = Institution::query()->create($data);

            // Désigne éventuellement un premier administrateur institutionnel.
            if (! empty($data['admin_user_id'])) {
                $admin = User::query()->findOrFail($data['admin_user_id']);
                $this->context->runAs($institution, function () use ($admin) {
                    InstitutionUserMembership::query()->create(['user_id' => $admin->id, 'status' => 'active']);
                    UserRole::query()->create([
                        'user_id' => $admin->id,
                        'role_id' => Role::query()->whereNull('institution_id')->where('code', 'institution_admin')->value('id'),
                    ]);
                });
            }

            return $institution;
        });

        $this->audit->log('institution.created', $institution, [], $institution->only(['name', 'code']), $institution->id);

        return ApiResponse::success(new InstitutionResource($institution), __('messages.created'), 201);
    }

    public function show(Institution $institution): JsonResponse
    {
        return ApiResponse::success(new InstitutionResource($institution));
    }

    public function update(Request $request, Institution $institution): JsonResponse
    {
        $data = $this->validated($request, $institution);
        $old = $institution->only(array_keys($data));
        $institution->update($data);
        $this->audit->log('institution.updated', $institution, $old, $institution->only(array_keys($data)), $institution->id);

        return ApiResponse::success(new InstitutionResource($institution), __('messages.updated'));
    }

    private function validated(Request $request, ?Institution $institution = null): array
    {
        $required = $institution ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:200'],
            'code' => [$required, 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('institutions', 'code')->ignore($institution?->id)],
            'description' => ['nullable', 'string', 'max:5000'],
            'country_code' => ['sometimes', 'string', 'size:2'],
            'timezone' => ['sometimes', 'timezone:all'],
            'default_language' => ['sometimes', Rule::in(['fr', 'en'])],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'admin_user_id' => [$institution ? 'prohibited' : 'nullable', 'uuid'],
        ]);

        if (isset($data['code'])) {
            $data['code'] = Str::upper($data['code']);
        }

        return $data;
    }
}
