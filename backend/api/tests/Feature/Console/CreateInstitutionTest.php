<?php

namespace Tests\Feature\Console;

use App\Models\Institution;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CreateInstitutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_institution_and_an_admin_who_can_sign_in(): void
    {
        $this->seed(RbacSeeder::class);

        $this->assertSame(0, Artisan::call('cousp:create-institution', ['code' => 'cousp', 'name' => 'COUSP', '--admin-email' => 'Admin@Cousp.cd']));
        preg_match('/provisoire.*: (\S+)$/m', Artisan::output(), $m);
        $this->assertNotEmpty($m[1] ?? null);

        $this->assertTrue(Institution::query()->withoutGlobalScopes()->where('code', 'COUSP')->exists());
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'admin@cousp.cd', 'password' => $m[1]])->assertOk();
        $me = $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$login->json('data.access_token')])->assertOk();
        $this->assertContains('users.manage', $me->json('data.permissions'));

        // Relancer la commande ne crée rien de plus et ne change pas le mot de passe.
        $this->artisan('cousp:create-institution', ['code' => 'COUSP', 'name' => 'COUSP', '--admin-email' => 'admin@cousp.cd'])->assertSuccessful();
        $this->assertSame(1, User::query()->where('email', 'admin@cousp.cd')->count());
    }
}
