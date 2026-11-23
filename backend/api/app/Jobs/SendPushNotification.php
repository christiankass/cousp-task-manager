<?php

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Models\Institution;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;

/**
 * Envoi via le service Expo Push, qui relaie vers Firebase Cloud Messaging (Android)
 * et APNs (iOS). Les jetons refusés par Expo (application désinstallée) sont supprimés.
 */
class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60];

    /** @param list<string> $tokens */
    public function __construct(
        public string $institutionId,
        public array $tokens,
        public string $title,
        public string $body,
        public array $data = [],
    ) {}

    public function handle(TenantContext $context): void
    {
        $messages = array_map(fn ($token) => [
            'to' => $token,
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
            'sound' => 'default',
            'priority' => 'high',
        ], $this->tokens);

        $request = Http::acceptJson()->timeout(15);
        if ($accessToken = config('cousp.expo_access_token')) {
            $request = $request->withToken($accessToken);
        }

        $response = $request->post(config('cousp.expo_push_url'), $messages)->throw();

        $invalid = collect($response->json('data', []))
            ->map(fn ($ticket, $i) => ($ticket['details']['error'] ?? null) === 'DeviceNotRegistered' ? $this->tokens[$i] ?? null : null)
            ->filter()
            ->values();

        if ($invalid->isNotEmpty()) {
            $institution = $context->withoutIsolation(fn () => Institution::query()->find($this->institutionId));
            $context->runAs($institution, fn () => DeviceToken::query()->whereIn('token', $invalid)->delete());
        }
    }
}
