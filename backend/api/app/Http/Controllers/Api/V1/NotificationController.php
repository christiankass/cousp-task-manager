<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Centre de notifications (§26) : chacun ne voit que les siennes, dans l'institution active. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->latest('created_at')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        $items->getCollection()->transform(fn ($n) => [
            'id' => $n->id,
            'type' => $n->notification_type,
            'title' => $n->title,
            'message' => $n->message,
            'related_entity_type' => $n->related_entity_type,
            'related_entity_id' => $n->related_entity_id,
            'read_at' => $n->read_at?->toIso8601ZuluString(),
            'created_at' => $n->created_at?->toIso8601ZuluString(),
        ]);

        return ApiResponse::success($items->items(), meta: ['pagination' => [
            'page' => $items->currentPage(), 'per_page' => $items->perPage(), 'total' => $items->total(), 'last_page' => $items->lastPage(),
        ]]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success(['count' => AppNotification::query()->where('user_id', $request->user()->id)->whereNull('read_at')->count()]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = AppNotification::query()->where('user_id', $request->user()->id)->findOrFail($id);
        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return ApiResponse::success(null, __('messages.updated'));
    }

    public function markAllRead(Request $request): JsonResponse
    {
        AppNotification::query()->where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return ApiResponse::success(null, __('messages.updated'));
    }
}
