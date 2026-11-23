# Essayer COUSP Task Manager sur son ordinateur

Ce mode d'emploi permet d'essayer toute l'application sur un PC, avec des données d'exemple.
Rien n'est envoyé sur Internet : tout reste sur l'ordinateur.

## 1. Installer Docker Desktop (une seule fois)

1. Télécharger Docker Desktop : https://www.docker.com/products/docker-desktop/
2. L'installer, puis redémarrer l'ordinateur si demandé.
3. Ouvrir Docker Desktop et attendre que la baleine en bas à gauche soit verte (« Engine running »).

## 2. Démarrer l'application

Dans le dossier du projet, double-cliquer sur **`demarrer-demo.bat`**.

La première fois, la préparation prend 5 à 15 minutes selon la connexion Internet.
Les fois suivantes, moins d'une minute. Le navigateur s'ouvre tout seul sur
**http://localhost:8080**.

## 3. Se connecter

Mot de passe de tous les comptes : **`Cousp@2026!`**

| Compte | Ce qu'il permet de voir |
|---|---|
| coordonnateur@cousp.local | Tout le COUSP : tableau de bord, urgences, réunions, rapports |
| directeur.surv@cousp.local | Une direction et ses services |
| chef.data@cousp.local | Un service, la validation des tâches de son équipe |
| agent@cousp.local | Ses propres tâches |
| admin@cousp.local | Administration : utilisateurs, structures, rôles |
| admin@insp.local | Une autre institution (INSP) : on vérifie qu'elle ne voit rien du COUSP |

Les e-mails envoyés par l'application (notifications) s'affichent sur **http://localhost:8025**.

## 4. Arrêter, recommencer

- **`arreter-demo.bat`** : arrête l'application. Les essais sont conservés.
- **`reinitialiser-demo.bat`** : efface tous les essais et repart des données d'exemple.

## En cas de problème

- « Le démarrage a échoué » : vérifier que Docker Desktop est ouvert et que la baleine est verte, puis relancer.
- La page ne s'affiche pas : attendre une minute et actualiser.
- Le port 8080 est déjà utilisé par un autre logiciel : fermer ce logiciel, ou demander de l'aide.

> Ce mode d'essai utilise des mots de passe connus de tous. Pour la vraie mise en ligne,
> suivre [le guide de déploiement](deploiement/guide-deploiement.md).
