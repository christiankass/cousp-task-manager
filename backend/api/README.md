# API COUSP Task Manager (Laravel)

Voir le [README principal](../../README.md) pour l'installation.

Points d'entrée (préfixe `/api/v1`) :

| Méthode | Endpoint | Permission |
|---|---|---|
| POST | `/auth/login` | — |
| POST | `/auth/logout`, `/auth/change-password` | connecté |
| GET | `/auth/me`, `/auth/institutions` | connecté |
| GET | `/organization-units` (`?tree=1`), `/organization-units/{id}`, `/organization-units/{id}/members` | `org_units.view` |
| POST/PUT/DELETE | `/organization-units…`, membres | `org_units.manage` |
| GET | `/users`, `/users/{id}` | `users.view` |
| POST/PUT/PATCH | `/users`, `/users/{id}`, `/users/{id}/status` | `users.manage` |
| POST/DELETE | `/users/{id}/roles` | `roles.assign` |
| GET/POST/PUT | `/platform/institutions…` | super administrateur de plateforme |

Code clé : `app/Support/Tenancy/TenantContext.php`, `app/Models/Concerns/BelongsToInstitution.php`,
`database/migrations/*_enable_row_level_security.php`, `database/seeders/RbacSeeder.php`.
