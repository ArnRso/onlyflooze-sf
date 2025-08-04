.PHONY: help phpstan test install cache-clear

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Targets:'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  %-15s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

phpstan: ## Run PHPStan static analysis
	php -d memory_limit=512M vendor/bin/phpstan analyse

test: ## Run PHPUnit tests
	vendor/bin/phpunit

install: ## Install Composer dependencies
	composer install

cache-clear: ## Clear Symfony cache
	symfony console cache:clear

migration: ## Run database migrations
	symfony console doctrine:migrations:migrate --no-interaction

asset-compile: ## Compile assets
	symfony console asset-map:compile