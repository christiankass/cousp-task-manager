<?php

namespace Tests\Feature\Auth;

use App\Models\InstitutionUserMembership;
use App\Models\LoginHistory;
use Tests\TestCase;

class LoginTest extends TestCase
{
    public function test_login_returns_a_token_bound_to_the_institution(): void
    {
        $cousp = $this->institution('COUSP');
        $agent = $this->member($cousp, 'agent');

        $this->postJson('/api/v1/auth/login', ['email' => $agent->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.institution.code', 'COUSP')
            ->assertJsonMissingPath('data.user.password');

        $this->assertDatabaseHas('personal_access_tokens', ['institution_id' => $cousp->id]);
        $this->assertTrue(LoginHistory::query()->where('email', $agent->email)->where('succeeded', true)->exists());
    }

    public function test_wrong_password_is_rejected_and_account_locks_after_five_attempts(): void
    {
        $cousp = $this->institution('COUSP');
        $agent = $this->member($cousp, 'agent');

        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/auth/login', ['email' => $agent->email, 'password' => 'wrong'])
                ->assertUnauthorized()
                ->assertJsonPath('code', 'INVALID_CREDENTIALS');
        }

        // Même le bon mot de passe est refusé pendant le verrouillage.
        $this->postJson('/api/v1/auth/login', ['email' => $agent->email, 'password' => self::PASSWORD])
            ->assertStatus(429)
            ->assertJsonPath('code', 'ACCOUNT_LOCKED');
    }

    public function test_a_user_of_several_institutions_must_choose_one(): void
    {
        $cousp = $this->institution('COUSP');
        $insp = $this->institution('INSP');
        $user = $this->member($cousp, 'agent');
        $this->addToInstitution($user, $insp, 'observer');

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INSTITUTION_REQUIRED');

        $headers = $this->loginAs($user, $insp);
        $this->getJson('/api/v1/auth/me', $headers)
            ->assertOk()
            ->assertJsonPath('data.institution.code', 'INSP')
            ->assertJsonPath('data.permissions', ['org_units.view', 'reports.view']);

        $this->getJson('/api/v1/auth/institutions', $headers)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_cannot_log_into_an_institution_the_user_does_not_belong_to(): void
    {
        $cousp = $this->institution('COUSP');
        $this->institution('INSP');
        $agent = $this->member($cousp, 'agent');

        $this->postJson('/api/v1/auth/login', [
            'email' => $agent->email, 'password' => self::PASSWORD, 'institution_code' => 'INSP',
        ])->assertUnprocessable();
    }

    public function test_suspended_membership_blocks_existing_tokens(): void
    {
        $cousp = $this->institution('COUSP');
        $agent = $this->member($cousp, 'agent');
        $headers = $this->loginAs($agent, $cousp);

        InstitutionUserMembership::query()->where('user_id', $agent->id)->update(['status' => 'suspended']);

        $this->getJson('/api/v1/auth/me', $headers)->assertForbidden()->assertJsonPath('code', 'INSTITUTION_FORBIDDEN');
    }

    public function test_logout_revokes_the_token(): void
    {
        $cousp = $this->institution('COUSP');
        $agent = $this->member($cousp, 'agent');
        $headers = $this->loginAs($agent, $cousp);

        $this->postJson('/api/v1/auth/logout', [], $headers)->assertOk();
        $this->getJson('/api/v1/auth/me', $headers)->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_messages_follow_the_user_language(): void
    {
        $cousp = $this->institution('COUSP');
        $agent = $this->member($cousp, 'agent', attributes: ['preferred_language' => 'en']);
        $headers = $this->loginAs($agent, $cousp);

        $this->postJson('/api/v1/organization-units', [], $headers)->assertForbidden()->assertJsonPath('message', 'Permission denied');

        $this->postJson('/api/v1/auth/login', ['email' => 'x@y.z', 'password' => 'bad'], ['Accept-Language' => 'fr'])
            ->assertJsonPath('message', 'Identifiants incorrects.');
    }
}
