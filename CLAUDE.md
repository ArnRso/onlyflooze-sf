# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**OnlyFlooze V2** : application de budgeting personnel mono-utilisateur (Symfony 8, PostgreSQL, FrankenPHP).
Elle importe les exports CSV du compte bancaire, suggère la catégorie des transactions en apprenant
des corrections manuelles (jamais d'application automatique), et suit les dépenses/revenus prévisibles
(récurrences) du mois en cours.
La spécification de référence est le document « SPEC-appli-budget » (cascade de matching, dédoublonnage
par comptage, récurrences par promotion, etc.).

## Common Commands

### Development

- **Start database**: `docker compose up -d` (PostgreSQL 16)
- **Install dependencies**: `composer install`
- **Dev server**: `symfony server:start -d`
- **Database migrations**: `symfony console doctrine:migrations:migrate`
- **Create migration**: `symfony console make:migration`
- **Create/update the single user**: `symfony console app:user:create <email> [password]`
- **Consolidate learned rules**: `symfony console app:rules:consolidate [--dry-run] [-v]` (tourne aussi après
  chaque import ; à planifier périodiquement en prod)
- **Sync prod DB to local**: `./sync-db-from-prod.sh` (hôte SSH `docker`, conteneur `onlyflooze_db`)

### Testing & Quality

- **Run all tests**: `vendor/bin/phpunit` (la DB de test `app_test` doit exister :
  `symfony console doctrine:database:create --env=test` puis `doctrine:migrations:migrate --env=test`)
- **Run specific test**: `vendor/bin/phpunit tests/path/to/TestFile.php`
- **Static analysis**: `make phpstan` (niveau 8)
- **PHP CS**: `make php-cs-fixer` / `make php-cs-fixer-fix`
- **Twig CS**: `make twigcs` / `make twigcs-fix`
- **ESLint**: `make eslint`
- **Tout**: `make lint`

## Architecture & Key Components

### Architecture Philosophy

**IMPORTANT**: This application follows a **service-oriented architecture** where:

- **Controllers must be kept as lightweight as possible** - they should only handle HTTP concerns (request/response)
- **All business logic must be placed in dedicated services** in the `src/Service/` directory
- Controllers should only: validate input, call services, and return responses
- La logique métier est de la **logique serveur pure** (services testés PHPUnit), jamais dans le navigateur.

### Domain (src/Entity/)

- **Transaction** (`bank_transaction`) : montant signé en centimes, type (préfixe CARTE/PRLV/VIR/ECH PRET/F…),
  tokens normalisés, catégorie + source (unclassified/manual), suggestion, règle matchée, récurrence,
  dedupKey (comptage, PAS d'unicité), tags.
- **Category** : 2 niveaux max (parent nullable), seed dans une migration. Les règles pointent des id.
- **CategorizationRule** : tokens discriminants (intersection), fingerprints exacts, scope par sens,
  sous-règle par montant (PayPal), compteurs confirmations/corrections (indicateurs de confiance).
- **Recurrence** : jour attendu, montant attendu = moyenne des 2-3 dernières occurrences, tolérance ±15 %.
- **ImportBatch** : résumé d'un import.
- **User** : mono-utilisateur, créé via `app:user:create`, pas d'inscription publique.

### Services (src/Service/)

- `Normalization/LabelNormalizer` : préfixe → type, tokens du marchand (dates/refs/montants retirés,
  découpage sur espaces ET tirets), date d'achat carte.
- `Import/BankCsvParser` : CSV `;`, montants français, encodage détecté.
- `Import/TransactionImporter` : pipeline complet, dédoublonnage par comptage (N dans l'import, M en base → insère N−M).
- **Décision utilisateur (prime sur la spec)** : les règles ne catégorisent JAMAIS automatiquement, elles
  ne font que pré-remplir des suggestions à valider en un clic — à l'import comme à la réapplication
  (`Review/RuleReapplier`, déclenchée après chaque apprentissage + bouton sur la file de révision).
- `Matching/MatchingEngine` : cascade exact → token (mot entier UNIQUEMENT, jamais de sous-chaîne) → fuzzy →
  montant+périodicité. `Matching/RuleLearner` : apprentissage/renforcement/dégradation des règles.
- `Matching/GenericTokenDetector` (pur) + `TokenSelectivity` : tokens génériques déduits du corpus (mots-outils,
  fréquence, dispersion en catégories, position en queue de libellé = ville/suffixe). Une règle ne repose
  JAMAIS sur un token générique ; sans token discriminant, elle ne matche qu'à l'empreinte exacte.
- `Matching/RuleConsolidator` : auto-amélioration périodique (après import, bouton, commande) — nettoie /
  reconstruit / rétrograde / supprime les règles à la lumière du corpus courant, puis rejoue les suggestions
  (`RuleReapplier` retire celles devenues orphelines). Ne touche jamais à un token posé à la main.
- La transaction garde la trace de la suggestion au moment du tri (`suggestionAtReview`, `suggestionOutcome`,
  `reviewedAt`) : `Review/SuggestionPrecisionProvider` en tire couverture et justesse par mois (écran Règles).
- `Recurrence/RecurrenceDetector` (suggestions de promotion, jamais de création auto),
  `RecurrenceMatcher` (rattachement à l'import, écart de montant signalé), `RecurrenceStatusProvider`
  (états passée/à venir/en retard, reste-à-passer).
- `Review/TransactionCategorizer`, `Dashboard/DashboardService`, managers CRUD dans `Catalog/`.

### Frontend

- **Symfony AssetMapper** (pas de Webpack), **Turbo + Stimulus** (Symfony UX), **UX Chartjs**, **Bootstrap 5**.
- File de révision en Turbo Streams (ligne validée disparaît, compteur descend sans rechargement).
- JS dans `assets/` via AssetMapper, jamais inline dans les templates.

### Database

- **PostgreSQL 16** via Docker Compose, **Doctrine ORM 3 / DBAL 4**, migrations dans `migrations/`.
- La migration `Version20260729000000` a éradiqué le schéma V1 ; ne jamais la modifier.

### Deployment

- **Dockerfile FrankenPHP** (port 80, HTTP simple derrière le reverse proxy du serveur).
- L'entrypoint attend la DB puis joue `doctrine:migrations:migrate` : toute migration commitée part en prod
  au prochain pull de l'image `latest`.
- CI **GitHub Actions** (`.github/workflows/ci-cd.yml`) : phpstan + cs + phpunit (service Postgres), puis
  build/push Docker Hub `arnrso/onlyflooze-sf`.

## Development Notes

- **PHP 8.4+**, **Symfony 8.1**, attributs partout, uuid v7 (`symfony/uid`) pour toutes les entités — jamais d'id incrémental.
- **Montants toujours en centimes (int) signés** : négatif = débit.
- **Tout doit être testable et testé** : unitaires pour la logique pure, KernelTestCase + dama (rollback auto)
  pour l'intégration DB, WebTestCase pour les écrans.
- Pièges couverts par des tests (ne pas casser) : CHRONOVET ≠ CHRONO (mot entier), dérive de libellé
  (SFR, DGFIP, salaire), PayPal par montant, doublons légitimes (comptage), DGFIP hors tolérance
  (rattaché mais signalé), numéros de prêt jamais fuzzy-matchés, règles jamais dégénérées en
  mot-outil/ville (garde de sélectivité), ANN CARTE rapprochée de son achat d'origine (nature
  remboursement), récurrences bornées (première occurrence → date de fin).
- Le système propose, l'utilisateur dispose : jamais de création/fusion silencieuse.

## Security & Authorization

- Mono-utilisateur : `access_control` exige ROLE_USER partout sauf `/login` et `/health`.
- Des voters ne sont utiles que si une vraie question d'autorisation apparaît (pas d'ownership ici).
  Si on en crée un, utiliser le FQCN de la constante du voter dans `isGranted`.

## Formatting and Display Guidelines

- Sur tous les endroits où on affiche des prix, ne jamais utiliser d'espaces normaux mais des espaces
  insécables : passer par le filtre Twig `money` (`MoneyExtension`).
