<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Enregistrement du téléphone de l'utilisateur pour les notifications push. */
class DeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:255', 'regex:/^(ExponentPushToken|ExpoPushToken)\[[^\]]+\]$/'],
            'platform' => ['required', Rule::in(['android', 'ios'])],
        ]);

        // Un téléphone appartient à la dernière personne qui s'y est connectée.
        DeviceToken::query()->updateOrCreate(
            ['token' => $data['token']],
            ['user_id' => $request->user()->id, 'platform' => $data['platform'], 'last_seen_at' => now()]
        );

        return ApiResponse::success(null, __('messages.created'), 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);
        DeviceToken::query()->where('user_id', $request->user()->id)->where('token', $data['token'])->delete();

        return response()->json(null, 204);
    }
}
