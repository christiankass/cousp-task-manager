<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/** Format standard des réponses (§27 du dossier de conception). */
class ApiResponse
{
    public static function success(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): JsonResponse
    {
        if ($data instanceof ResourceCollection && $data->resource instanceof LengthAwarePaginator) {
            $paginator = $data->resource;
            $meta += [
                'pagination' => [
                    'page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ];
        }

        if ($data instanceof LengthAwarePaginator) {
            $meta += ['pagination' => [
                'page' => $data->currentPage(),
                'per_page' => $data->perPage(),
                'total' => $data->total(),
                'last_page' => $data->lastPage(),
            ]];
            $data = $data->items();
        }

        return response()->json([
            'success' => true,
            'message' => $message ?? __('messages.ok'),
            'data' => $data instanceof JsonResource ? $data->resolve(request()) : $data,
            'meta' => ['timestamp' => now()->utc()->toIso8601ZuluString()] + $meta,
        ], $status);
    }

    public static function error(string $message, string $code, int $status, array $errors = []): JsonResponse
    {
        return response()->json(array_filter([
            'success' => false,
            'message' => $message,
            'errors' => $errors ?: null,
            'code' => $code,
        ], fn ($v) => $v !== null), $status);
    }
}
