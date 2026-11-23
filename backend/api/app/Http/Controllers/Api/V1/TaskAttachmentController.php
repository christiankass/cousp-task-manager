<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TaskAttachment;
use App\Services\AuditLogger;
use App\Services\Tasks\TaskHistory;
use App\Support\ApiResponse;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pièces jointes et livrables. Les fichiers sont stockés hors du dossier public,
 * sous un nom aléatoire, et ne sont servis qu'après contrôle d'accès à la tâche.
 */
class TaskAttachmentController extends Controller
{
    public function __construct(private TenantContext $context, private TaskHistory $history, private AuditLogger $audit) {}

    public function index(Request $request, string $id): JsonResponse
    {
        $task = TaskController::findVisible($request, $id);

        return ApiResponse::success($task->attachments()->with('uploader')->latest()->get()->map(fn ($a) => $this->present($a)));
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $task = TaskController::findVisible($request, $id);
        $user = $request->user();
        abort_unless($task->isAssignee($user) || $task->created_by === $user->id, 403, __('messages.forbidden'));

        $request->validate([
            'file' => ['required', 'file', 'max:'.config('cousp.attachments.max_kb'), 'mimes:'.implode(',', config('cousp.attachments.mimes'))],
        ]);

        $file = $request->file('file');
        $disk = config('cousp.attachments.disk');
        $path = $file->storeAs(
            'attachments/'.$this->context->id().'/'.$task->id,
            Str::uuid().'.'.$file->extension(),
            $disk
        );

        $attachment = TaskAttachment::query()->create([
            'task_id' => $task->id,
            'uploaded_by' => $user->id,
            'file_name' => Str::limit(basename($file->getClientOriginalName()), 250, ''),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'storage_path' => $path,
            'checksum' => hash_file('sha256', $file->getRealPath()),
        ]);

        $this->history->record($task, 'attachment_added', null, null, $user, null, ['file_name' => $attachment->file_name]);

        return ApiResponse::success($this->present($attachment->load('uploader')), __('messages.created'), 201);
    }

    public function download(Request $request, string $id, string $attachmentId): StreamedResponse
    {
        $task = TaskController::findVisible($request, $id);
        $attachment = TaskAttachment::query()->where('task_id', $task->id)->findOrFail($attachmentId);
        $this->audit->log('task.attachment_downloaded', $attachment);

        return Storage::disk(config('cousp.attachments.disk'))->download($attachment->storage_path, $attachment->file_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function present(TaskAttachment $a): array
    {
        return [
            'id' => $a->id,
            'file_name' => $a->file_name,
            'mime_type' => $a->mime_type,
            'file_size' => $a->file_size,
            'checksum' => $a->checksum,
            'uploaded_by' => ['id' => $a->uploader->id, 'name' => $a->uploader->name],
            'created_at' => $a->created_at?->toIso8601ZuluString(),
        ];
    }
}
