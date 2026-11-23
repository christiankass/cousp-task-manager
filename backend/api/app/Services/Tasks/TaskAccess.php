<?php

namespace App\Services\Tasks;

use App\Models\ApprovalStep;
use App\Models\OrganizationUnit;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Eloquent\Builder;

/**
 * Règles de visibilité et d'autorité sur les tâches (§5, §29.1).
 *
 * Un rôle attribué sans structure vaut pour toute l'institution ; un rôle attribué
 * sur une structure vaut pour elle et ses sous-structures.
 */
class TaskAccess
{
    /** @var array<string, list<string>|null> */
    private array $cache = [];

    /**
     * Structures sur lesquelles l'utilisateur détient la permission.
     *
     * @return list<string>|null null = toute l'institution
     */
    public function unitIdsFor(User $user, string $permission): ?array
    {
        $key = $user->id.'|'.$permission;

        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $unitIds = UserRole::query()
            ->where('user_id', $user->id)
            ->whereHas('role.permissions', fn ($q) => $q->where('code', $permission))
            ->pluck('organization_unit_id');

        if ($unitIds->isEmpty()) {
            return $this->cache[$key] = [];
        }

        if ($unitIds->contains(null)) {
            return $this->cache[$key] = null;
        }

        $all = [];
        foreach (OrganizationUnit::query()->whereIn('id', $unitIds->unique()->all())->get() as $unit) {
            $all = [...$all, ...$unit->descendantIds()];
        }

        return $this->cache[$key] = array_values(array_unique($all));
    }

    public function hasForUnit(User $user, string $permission, string $unitId): bool
    {
        $ids = $this->unitIdsFor($user, $permission);

        return $ids === null || in_array($unitId, $ids, true);
    }

    /** Tâches que l'utilisateur peut consulter. */
    public function visibleQuery(User $user): Builder
    {
        $query = Task::query();

        if ($user->hasPermission('tasks.view_all')) {
            return $query;
        }

        $unitIds = array_values(array_unique([
            ...($this->unitIdsFor($user, 'tasks.view_unit') ?? ['*']),
            ...($this->unitIdsFor($user, 'tasks.validate') ?? ['*']),
        ]));

        if (in_array('*', $unitIds, true)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user, $unitIds) {
            $q->where('tasks.created_by', $user->id)
                ->orWhereIn('tasks.id', TaskAssignment::query()->select('task_id')->where('user_id', $user->id))
                ->orWhereIn('tasks.id', ApprovalStep::query()
                    ->join('approval_workflows', 'approval_workflows.id', '=', 'approval_steps.workflow_id')
                    ->where('approval_steps.approver_id', $user->id)
                    ->select('approval_workflows.task_id'));

            if ($unitIds !== []) {
                $q->orWhereIn('tasks.organization_unit_id', $unitIds);
            }
        });
    }

    public function canView(User $user, Task $task): bool
    {
        return $this->visibleQuery($user)->whereKey($task->id)->exists();
    }

    /** Modifier, réaffecter : créateur de la tâche ou titulaire de tasks.assign sur la structure. */
    public function canManage(User $user, Task $task): bool
    {
        return $task->created_by === $user->id
            || $this->hasForUnit($user, 'tasks.assign', $task->organization_unit_id);
    }

    /** Exécuter : responsable ou collaborateur. */
    public function canExecute(User $user, Task $task): bool
    {
        return $task->isAssignee($user);
    }
}
