<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ApproverType;
use App\Enums\TaskType;
use App\Http\Controllers\Controller;
use App\Models\ApprovalTemplate;
use App\Models\InstitutionUserMembership;
use App\Models\OrganizationUnit;
use App\Models\Role;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Configuration des circuits de validation (§22). */
class ApprovalTemplateController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(ApprovalTemplate::query()->with('steps')->orderBy('name')->get()->map(fn ($t) => $this->present($t)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $template = DB::transaction(function () use ($data) {
            $template = ApprovalTemplate::query()->create(collect($data)->except('steps')->all());
            $this->saveSteps($template, $data['steps']);

            return $template;
        });

        $this->audit->log('approval_template.created', $template, [], $data);

        return ApiResponse::success($this->present($template->load('steps')), __('messages.created'), 201);
    }

    /** Les workflows déjà lancés gardent leurs étapes : seule la configuration change. */
    public function update(Request $request, string $id): JsonResponse
    {
        $template = ApprovalTemplate::query()->findOrFail($id);
        $data = $this->validated($request, partial: true);

        DB::transaction(function () use ($template, $data) {
            $template->update(collect($data)->except('steps')->all());
            if (isset($data['steps'])) {
                $template->steps()->delete();
                $this->saveSteps($template, $data['steps']);
            }
        });

        $this->audit->log('approval_template.updated', $template, [], $data);

        return ApiResponse::success($this->present($template->load('steps')), __('messages.updated'));
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$req, 'string', 'max:150'],
            'task_type' => ['nullable', Rule::enum(TaskType::class)],
            'organization_unit_id' => ['nullable', 'uuid'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'steps' => [$req, 'array', 'min:1', 'max:10'],
            'steps.*.approver_type' => ['required', Rule::enum(ApproverType::class)],
            'steps.*.role_code' => ['required_if:steps.*.approver_type,role', 'nullable', 'string'],
            'steps.*.user_id' => ['required_if:steps.*.approver_type,user', 'nullable', 'uuid'],
            'steps.*.is_required' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['organization_unit_id']) && ! OrganizationUnit::query()->whereKey($data['organization_unit_id'])->exists()) {
            throw ValidationException::withMessages(['organization_unit_id' => [__('validation.exists', ['attribute' => 'organization_unit_id'])]]);
        }

        foreach ($data['steps'] ?? [] as $i => $step) {
            if (! empty($step['user_id']) && ! InstitutionUserMembership::query()->where('user_id', $step['user_id'])->exists()) {
                throw ValidationException::withMessages(["steps.{$i}.user_id" => [__('messages.user_not_in_institution')]]);
            }
            if (($step['approver_type'] === 'role') && ! Role::query()->where('code', $step['role_code'])->exists()) {
                throw ValidationException::withMessages(["steps.{$i}.role_code" => [__('validation.exists', ['attribute' => 'role_code'])]]);
            }
        }

        return $data;
    }

    private function saveSteps(ApprovalTemplate $template, array $steps): void
    {
        foreach (array_values($steps) as $i => $step) {
            $template->steps()->create([
                'step_order' => $i + 1,
                'approver_type' => $step['approver_type'],
                'approver_role_id' => $step['approver_type'] === 'role' ? Role::query()->where('code', $step['role_code'])->orderByRaw('institution_id is null')->value('id') : null,
                'approver_user_id' => $step['approver_type'] === 'user' ? $step['user_id'] : null,
                'is_required' => $step['is_required'] ?? true,
            ]);
        }
    }

    private function present(ApprovalTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'task_type' => $t->task_type?->value,
            'organization_unit_id' => $t->organization_unit_id,
            'is_default' => $t->is_default,
            'is_active' => $t->is_active,
            'steps' => $t->steps->map(fn ($s) => [
                'step_order' => $s->step_order,
                'approver_type' => $s->approver_type->value,
                'approver_role_id' => $s->approver_role_id,
                'approver_user_id' => $s->approver_user_id,
                'is_required' => $s->is_required,
            ]),
        ];
    }
}
