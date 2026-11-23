<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Tasks\TaskTestCase;

class PushNotificationTest extends TaskTestCase
{
    public function test_registered_phones_receive_push_notifications(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok']]])]);

        $agent = $this->loginAs($this->agent, $this->cousp);
        $this->postJson('/api/v1/devices', ['token' => 'not-a-token', 'platform' => 'android'], $agent)->assertUnprocessable();
        $this->postJson('/api/v1/devices', ['token' => 'ExponentPushToken[abc123]', 'platform' => 'android'], $agent)->assertCreated();

        $this->createTask();

        Http::assertSent(fn ($request) => $request->url() === 'https://exp.host/--/api/v2/push/send'
            && $request[0]['to'] === 'ExponentPushToken[abc123]'
            && str_starts_with($request[0]['title'], 'Nouvelle tâche')
            && $request[0]['data']['type'] === 'task_assigned');
    }

    public function test_unregistered_phones_are_forgotten(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]]])]);

        $this->postJson('/api/v1/devices', ['token' => 'ExponentPushToken[old]', 'platform' => 'ios'], $this->loginAs($this->agent, $this->cousp))->assertCreated();
        $this->createTask();

        $this->assertSame(0, DeviceToken::query()->count());
    }

    public function test_logout_detaches_the_phone(): void
    {
        Http::fake();
        $agent = $this->loginAs($this->agent, $this->cousp);
        $this->postJson('/api/v1/devices', ['token' => 'ExponentPushToken[xyz]', 'platform' => 'android'], $agent)->assertCreated();
        $this->postJson('/api/v1/auth/logout', ['device_token' => 'ExponentPushToken[xyz]'], $agent)->assertOk();

        $this->assertSame(0, DeviceToken::query()->count());
        Notification::assertNothingSent();
    }

    public function test_users_choose_their_language(): void
    {
        $agent = $this->loginAs($this->agent, $this->cousp);
        $this->putJson('/api/v1/auth/preferences', ['preferred_language' => 'de'], $agent)->assertUnprocessable();
        $this->putJson('/api/v1/auth/preferences', ['preferred_language' => 'en'], $agent)->assertOk();

        $this->assertSame('en', $this->agent->refresh()->preferred_language);
    }
}
