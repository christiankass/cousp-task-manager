<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    private function enable(User $user, array $headers): array
    {
        $secret = $this->postJson('/api/v1/auth/mfa/setup', [], $headers)->assertOk()->json('data.secret');
        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => '000000'], $headers)->assertUnprocessable();
        $codes = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)], $headers)
            ->assertOk()->json('data.recovery_codes');
        $this->assertCount(8, $codes);
        Cache::forget('mfa:last:'.$user->id);

        return [$secret, $codes];
    }

    public function test_login_requires_the_app_code_once_enabled(): void
    {
        $cousp = $this->institution('COUSP');
        $admin = $this->member($cousp, 'institution_admin', attributes: ['email' => 'admin@test.local']);
        $headers = $this->loginAs($admin, $cousp);

        $this->getJson('/api/v1/auth/me', $headers)->assertJsonPath('data.security.mfa_recommended', true);
        [$secret, $codes] = $this->enable($admin, $headers);
        $this->getJson('/api/v1/auth/me', $headers)->assertJsonPath('data.security.mfa_enabled', true);

        // Le mot de passe seul ne donne plus de jeton.
        $step1 = $this->postJson('/api/v1/auth/login', ['email' => 'admin@test.local', 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('data.mfa_required', true)->assertJsonMissingPath('data.access_token');
        $challenge = $step1->json('data.challenge_token');

        $this->postJson('/api/v1/auth/mfa/verify', ['challenge_token' => $challenge, 'code' => '123456'])->assertUnauthorized();
        $token = $this->postJson('/api/v1/auth/mfa/verify', ['challenge_token' => $challenge, 'code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertOk()->json('data.access_token');
        $this->assertNotEmpty($token);

        // Le défi ne sert qu'une fois ; un code de secours fonctionne une seule fois.
        $this->postJson('/api/v1/auth/mfa/verify', ['challenge_token' => $challenge, 'code' => '000000'])->assertUnauthorized()->assertJsonPath('code', 'MFA_EXPIRED');
        $c2 = $this->postJson('/api/v1/auth/login', ['email' => 'admin@test.local', 'password' => self::PASSWORD])->json('data.challenge_token');
        $this->postJson('/api/v1/auth/mfa/verify', ['challenge_token' => $c2, 'recovery_code' => $codes[0]])->assertOk();
        $c3 = $this->postJson('/api/v1/auth/login', ['email' => 'admin@test.local', 'password' => self::PASSWORD])->json('data.challenge_token');
        $this->postJson('/api/v1/auth/mfa/verify', ['challenge_token' => $c3, 'recovery_code' => $codes[0]])->assertUnauthorized();
    }

    public function test_privileged_accounts_can_be_forced_to_enable_it(): void
    {
        config(['cousp.mfa_enforce_privileged' => true]);
        $cousp = $this->institution('COUSP');
        $admin = $this->member($cousp, 'institution_admin');
        $agent = $this->member($cousp, 'agent');

        $headers = $this->loginAs($admin, $cousp);
        $this->getJson('/api/v1/users', $headers)->assertForbidden()->assertJsonPath('code', 'MFA_SETUP_REQUIRED');
        $this->getJson('/api/v1/auth/me', $headers)->assertOk()->assertJsonPath('data.security.mfa_enforced', true);
        $this->enable($admin, $headers);
        $this->getJson('/api/v1/users', $headers)->assertOk();

        // Un agent n'est pas concerné.
        $this->getJson('/api/v1/tasks', $this->loginAs($agent, $cousp))->assertOk();
    }
}
