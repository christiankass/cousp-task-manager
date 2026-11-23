# COUSP Task Manager

Plateforme institutionnelle de planification, d'attribution, d'exécution et de suivi des tâches
du COUSP, ouverte à d'autres institutions (INSP…). Cahier des charges et dossier de conception :
[`documentation/cahier-des-charges`](documentation/cahier-des-charges).

## Organisation du dépôt

```
apps/web/          Application web (Next.js)
apps/mobile/       Application mobile (Expo) — lot 2
backend/api/       API REST (Laravel 13, PostgreSQL, Sanctum)
packages/          Code partagé web/mobile — à venir
infrastructure/    Docker, Nginx
documentation/     Cahier des charges et conception
```

## Avancement

| Lot | Contenu | État |
|-----|---------|------|
| 0 | Socle : institutions, structures, utilisateurs, rôles, connexion, isolation, audit, FR/EN | ✅ |
| 1 | Tâches, validation hiérarchique, retards, notifications, tableau de bord | ✅ |
| 2 | Application mobile | à faire |
| 3 | Réunions/recommandations, événements sanitaires, missions | à faire |
| 4 | Rapports PDF/Excel, calendrier, Kanban, MFA, déploiement | à faire |

## Démarrage en local

Prérequis : PHP 8.3+, Composer, Node 20+, PostgreSQL 16 (ou Docker).

```bash
# Base de données et services (PostgreSQL, Redis, Mailpit)
docker compose up -d postgres redis mailpit

# API
cd backend/api
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed        # crée les rôles et des données de démonstration
php artisan serve --port=8000

# Web (autre terminal)
cd apps/web
cp .env.example .env.local
npm install
npm run dev                       # http://localhost:3000
```

Comptes de démonstration (mot de passe `Cousp@2026!`) :

| Compte | Profil |
|--------|--------|
| admin@cousp.local | Administrateur institutionnel COUSP |
| coordonnateur@cousp.local | Coordonnateur général |
| directeur.surv@cousp.local | Responsable de direction (Surveillance) |
| chef.data@cousp.local | Chef de service (Analyse des données) |
| agent@cousp.local | Agent |
| validateur@cousp.local | Validateur |
| observateur@cousp.local | Observateur |
| admin@insp.local, agent@insp.local | Comptes INSP (pour vérifier l'isolation) |
| superadmin@cousp-task.local | Super administrateur de plateforme |

## Tâches planifiées

Le repérage des retards et des échéances proches tourne toutes les 15 minutes :

```bash
php artisan schedule:work          # en développement
php artisan tasks:detect-deadlines # exécution manuelle
```

Les e-mails partent par la file d'attente (`php artisan queue:work`). En développement,
Mailpit les affiche sur http://localhost:8025 ; mettez `MAIL_NOTIFICATIONS=false` pour les couper.

## Tests

```bash
cd backend/api
php artisan test
```

Les tests utilisent PostgreSQL (base `cousp_task_test`) pour vérifier aussi l'isolation
par Row Level Security.

## Principes de sécurité du socle

- **Isolation des institutions** sur trois niveaux : le jeton de connexion est lié à une
  institution ; chaque modèle filtre sur `institution_id` ; PostgreSQL Row Level Security
  bloque toute lecture ou écriture hors de l'institution active, même en SQL brut.
  L'utilisateur PostgreSQL de l'application **ne doit pas être super-utilisateur**.
- **RBAC** : rôles système (§5) attribuables à l'institution entière ou à une structure ;
  un rôle sur une direction couvre ses sous-structures.
- Verrouillage après 5 échecs de connexion (15 min), historique des connexions,
  politique de mots de passe robustes, jetons expirant après 8 h, cookie httpOnly côté web.
- Journal d'audit en ajout seul, protégé par un déclencheur PostgreSQL.
