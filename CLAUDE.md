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

### Authentication System
- **User Entity**: `src/Entity/User.php` - Implements UserInterface and PasswordAuthenticatedUserInterface
- **User Repository**: `src/Repository/UserRepository.php` - Doctrine repository for User entity
- **Security Config**: `config/packages/security.yaml` - Form login with CSRF protection enabled

### Controllers
- **RegistrationController**: Handles user registration with automatic login after signup
- **SecurityController**: Manages login/logout routes (login logic handled by Symfony Security)

### Forms
- **RegistrationFormType**: User registration form with email, password, and terms agreement

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