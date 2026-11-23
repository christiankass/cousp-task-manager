<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TaskComment;
use App\Services\Notifier;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskCommentController extends Controller
{
    public function __construct(private Notifier $notifier) {}

    public function index(Request $request, string $id): JsonResponse
    {
        $task = TaskController::findVisible($request, $id);

        return ApiResponse::success($task->comments()->with('user')->get()->map(fn ($c) => $this->present($c)));
    }

    /** Toute personne qui voit la tâche peut la commenter ; les intervenants sont notifiés. */
    public function store(Request $request, string $id): JsonResponse
    {
        $task = TaskController::findVisible($request, $id);
        $data = $request->validate(['content' => ['required', 'string', 'max:10000']]);

        $comment = TaskComment::query()->create([
            'task_id' => $task->id,
            'user_id' => $request->user()->id,
            'content' => $data['content'],
        ]);

        $this->notifier->task('task_commented', $task, $task->assignments()->pluck('user_id')->push($task->created_by), $request->user());

        return ApiResponse::success($this->present($comment->load('user')), __('messages.created'), 201);
    }

    private function present(TaskComment $c): array
    {
        return [
            'id' => $c->id,
            'content' => $c->content,
            'author' => ['id' => $c->user->id, 'name' => $c->user->name],
            'created_at' => $c->created_at?->toIso8601ZuluString(),
        ];
    }
}
