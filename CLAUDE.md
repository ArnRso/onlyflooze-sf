# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **Symfony 7.3** web application with authentication features, using PostgreSQL as the database. The application includes user registration, login/logout functionality, and uses modern Symfony practices.

## Common Commands

### Development
- **Start database**: `docker compose up -d` (PostgreSQL with docker)
- **Install dependencies**: `composer install`
- **Asset compilation**: `symfony console asset-map:compile`
- **Database migrations**: `symfony console doctrine:migrations:migrate`
- **Create migration**: `symfony console make:migration`

### Testing
- **Run all tests**: `vendor/bin/phpunit`
- **Run specific test**: `vendor/bin/phpunit tests/path/to/TestFile.php`

### Console Commands
- **Symfony console**: `symfony console` (main entry point for all Symfony commands)
- **Cache clear**: `symfony console cache:clear`
- **Generate code**: `symfony console make:*` (controller, entity, form, etc.)

## Architecture & Key Components

### Architecture Philosophy
**IMPORTANT**: This application follows a **service-oriented architecture** where:
- **Controllers must be kept as lightweight as possible** - they should only handle HTTP concerns (request/response)
- **All business logic must be placed in dedicated services** in the `src/Service/` directory
- Controllers should only: validate input, call services, and return responses
- Services should be injected via dependency injection and contain all domain logic

### Authentication System
- **User Entity**: `src/Entity/User.php` - Implements UserInterface and PasswordAuthenticatedUserInterface
- **User Repository**: `src/Repository/UserRepository.php` - Doctrine repository for User entity
- **Security Config**: `config/packages/security.yaml` - Form login with CSRF protection enabled

### Controllers
- **HomeController**: Landing page controller
- **RegistrationController**: Handles user registration HTTP requests (business logic delegated to services)
- **SecurityController**: Manages login/logout HTTP routes (authentication logic handled by Symfony Security)

### Services
- **All business logic resides in `src/Service/` directory**
- Services handle domain operations, calculations, data processing, and complex workflows
- Controllers should inject and use these services rather than implementing logic directly

### Forms
- **RegistrationFormType**: User registration form with firstName, lastName, email, password, and terms agreement

### Database
- Uses **PostgreSQL** via Docker Compose
- **Doctrine ORM** for database operations
- **Migrations** in `migrations/` directory

### Frontend
- **Symfony AssetMapper** for asset management (no Webpack)
- **Twig templates** in `templates/` directory

### Key Configuration Files
- `composer.json`: PHP dependencies and scripts
- `config/packages/security.yaml`: Authentication and authorization rules
- `config/packages/doctrine.yaml`: Database configuration
- `phpunit.dist.xml`: Test configuration
- `compose.yaml`: Docker services (PostgreSQL)

## Development Notes

- **PHP 8.2+** required
- **Doctrine attributes** used instead of annotations
- **Route attributes** on controller methods
- **CSRF protection** enabled on forms and login
- **Password hashing** handled automatically by Symfony
- **User identifier**: email address
- **Service-first approach**: Always create services for business logic before implementing in controllers
- **Bootstrap 5.3.7** for frontend styling (no Stimulus/Turbo dependencies)

## Security & Authorization

### Voter Guidelines
- N'hésite pas a créer des voteurs quand on veut vérifier si un utilisateur peut réaliser une opération. D'ailleurs, quand on fera un isGranted, on mettra en paramètre le FQCN de la constante du voter

## Formatting and Display Guidelines

- Sur tous les endroits où on affiche des prix (avec number format par exemple), on utilise jamais d'espaces normaux mais des espaces insécables

## Database Entity Guidelines

- Pour toutes les entités, on utilise pas d'id incrémental mais un uuid