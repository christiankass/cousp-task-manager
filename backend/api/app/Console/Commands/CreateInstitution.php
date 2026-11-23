<?php

namespace App\Console\Commands;

use App\Models\Institution;
use App\Models\InstitutionUserMembership;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mise en service : crée une institution et son premier administrateur, sans données de démonstration.
 * Le mot de passe provisoire est affiché une seule fois : à changer dès la première connexion.
 *
 *   php artisan cousp:create-institution COUSP "Centre des Opérations d'Urgence de Santé Publique" \
 *       --admin-email=admin@cousp.cd --admin-name="Administrateur COUSP"
 */
class CreateInstitution extends Command
{
    protected $signature = 'cousp:create-institution
        {code : Code court de l\'institution (ex. COUSP)}
        {name : Nom complet}
        {--admin-email= : E-mail du premier administrateur}
        {--admin-name= : Nom du premier administrateur}
        {--timezone=Africa/Kinshasa : Fuseau horaire}
        {--platform-admin : Donne aussi à ce compte l\'administration de la plateforme}';

    protected $description = 'Crée une institution et son premier administrateur (mise en service)';

    public function handle(TenantContext $context, AuditLogger $audit): int
    {
        $code = Str::upper((string) $this->argument('code'));
        $email = Str::lower(trim((string) $this->option('admin-email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Indiquez un e-mail valide avec --admin-email.');

            return self::FAILURE;
        }

        $role = Role::query()->whereNull('institution_id')->where('code', 'institution_admin')->first();
        if (! $role) {
            $this->error('Rôles absents : lancez d\'abord « php artisan db:seed --force ».');

            return self::FAILURE;
        }

        $password = null;
        $institution = DB::transaction(function () use ($context, $code, $email, $role, &$password) {
            $institution = $context->withoutIsolation(fn () => Institution::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $this->argument('name'), 'timezone' => $this->option('timezone'), 'status' => 'active'],
            ));

            $user = User::query()->where('email', $email)->first();
            if (! $user) {
                // Lettres, chiffres et symboles : respecte la politique de mot de passe.
                $password = Str::password(16);
                $user = User::query()->create([
                    'name' => $this->option('admin-name') ?: $email,
                    'email' => $email,
                    'password' => $password,
                ]);
            }
            if ($this->option('platform-admin')) {
                $user->forceFill(['is_platform_admin' => true])->save();
            }

            $context->runAs($institution, function () use ($user, $role) {
                InstitutionUserMembership::query()->firstOrCreate(['user_id' => $user->id], ['status' => 'active']);
                UserRole::query()->firstOrCreate(['user_id' => $user->id, 'role_id' => $role->id, 'organization_unit_id' => null]);
            });

            return $institution;
        });

        $audit->log('institution.created', $institution, [], $institution->only(['name', 'code']), $institution->id);

        $this->info("Institution {$institution->code} prête : {$institution->name}");
        $this->line("Administrateur : {$email}");
        if ($password) {
            $this->warn("Mot de passe provisoire (affiché une seule fois) : {$password}");
        } else {
            $this->line('Compte existant : son mot de passe n\'a pas été changé.');
        }

        return self::SUCCESS;
    }
}
