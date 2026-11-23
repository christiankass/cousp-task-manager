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
| GET | `/tasks` (filtres `status`, `priority`, `task_type`, `organization_unit_id`, `assignee=me`, `overdue`, `search`, `sort`) | tâches visibles |
| POST | `/tasks` | `tasks.create` (+ `tasks.assign` pour confier à autrui) |
| GET/PUT/DELETE | `/tasks/{id}` | visibilité ; modification : créateur ou `tasks.assign` ; archivage : `tasks.delete` |
| PATCH | `/tasks/{id}/status`, `/tasks/{id}/progress` | personnes affectées |
| POST/DELETE | `/tasks/{id}/assignments` | gestionnaire, ou délégation par le responsable |
| GET/POST | `/tasks/{id}/comments`, `/tasks/{id}/attachments` | visibilité |
| GET | `/tasks/{id}/history`, `/tasks/{id}/approvals` | visibilité |
| POST | `/tasks/{id}/submit` | responsable principal |
| POST | `/tasks/{id}/approve`, `/reject`, `/request-correction` | valideur de l'étape en cours (déterminé par le serveur) |
| GET/POST/PUT | `/approval-templates` | `workflow.manage` |
| GET/PATCH/POST | `/notifications…` | ses propres notifications |
| GET | `/dashboard/overview`, `/dashboard/overdue` | tâches visibles |

Code clé : `app/Support/Tenancy/TenantContext.php`, `app/Models/Concerns/BelongsToInstitution.php`,
`database/migrations/*_enable_row_level_security.php`, `database/seeders/RbacSeeder.php`.
