.PHONY: $(wildcard *)

# Variables Docker
DOCKER_IMAGE_NAME = onlyflooze-sf
GIT_SHA = $(shell git rev-parse --short HEAD)
DOCKER_TAG_LATEST = $(DOCKER_IMAGE_NAME):latest
DOCKER_TAG_SHA = $(DOCKER_IMAGE_NAME):$(GIT_SHA)

help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Targets:'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  %-15s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

phpstan: ## Run PHPStan static analysis
	php -d memory_limit=512M vendor/bin/phpstan analyse

php-cs-fixer: ## Check PHP code style with PHP CS Fixer
	vendor/bin/php-cs-fixer fix --dry-run --diff

php-cs-fixer-fix: ## Fix PHP code style with PHP CS Fixer
	vendor/bin/php-cs-fixer fix

twigcs: ## Check Twig templates with TwigCS
	vendor/bin/twigcs templates

eslint: ## Check JavaScript code with ESLint
	npm run lint:js

eslint-fix: ## Fix JavaScript code with ESLint
	npm run lint:js:fix

lint: phpstan php-cs-fixer twigcs eslint ## Run all linting tools

lint-fix: php-cs-fixer-fix eslint-fix ## Fix code with all fixers

quality: lint ## Alias for lint command

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

docker-build: ## Build Docker image for current platform
	@echo "🏗️  Building Docker image for current platform..."
	@echo "📦 Tags: $(DOCKER_TAG_LATEST), $(DOCKER_TAG_SHA)"
	docker build -t $(DOCKER_TAG_LATEST) -t $(DOCKER_TAG_SHA) .
	@echo "✅ Build completed!"

docker-setup-buildx: ## Setup Docker buildx for multi-platform builds
	@echo "🔧 Setting up Docker buildx for multi-platform builds..."
	@if ! docker buildx ls | grep -q "multiplatform"; then \
		echo "📦 Creating new buildx builder 'multiplatform'..."; \
		docker buildx create --name multiplatform --platform linux/amd64,linux/arm64 --use; \
	else \
		echo "✅ Builder 'multiplatform' already exists, using it..."; \
		docker buildx use multiplatform; \
	fi
	@docker buildx inspect --bootstrap
	@echo "✅ Buildx setup completed!"

docker-build-multi: docker-setup-buildx ## Build multi-platform image and cache locally (AMD64 + ARM64)
	@echo "🏗️  Building multi-platform Docker image with local cache..."
	@echo "📦 Platforms: linux/amd64, linux/arm64"
	@echo "📦 Tags: $(DOCKER_TAG_LATEST), $(DOCKER_TAG_SHA)"
	@echo "⚠️  Note: Multi-platform images are cached but not loaded to Docker daemon"
	@echo "⏱️  Warning: This can take 20-30 minutes due to ARM64 emulation"
	docker buildx build \
		--platform linux/amd64,linux/arm64 \
		-t $(DOCKER_TAG_LATEST) \
		-t $(DOCKER_TAG_SHA) \
		.
	@echo "✅ Multi-platform build completed and cached!"

docker-build-fast: docker-setup-buildx ## Build for AMD64 only (faster, ~5 minutes)
	@echo "🚀 Building Docker image for AMD64 only (fast build)..."
	@echo "📦 Platform: linux/amd64"
	@echo "📦 Tags: $(DOCKER_TAG_LATEST), $(DOCKER_TAG_SHA)"
	docker buildx build \
		--platform linux/amd64 \
		-t $(DOCKER_TAG_LATEST) \
		-t $(DOCKER_TAG_SHA) \
		--load \
		.
	@echo "✅ Fast AMD64 build completed and loaded to Docker daemon!"

docker-build-local: ## Build for current platform and load to Docker daemon
	@echo "🏗️  Building Docker image for current platform..."
	@echo "📦 Platform: current ($(shell docker version --format '{{.Server.Arch}}')"
	@echo "📦 Tags: $(DOCKER_TAG_LATEST), $(DOCKER_TAG_SHA)"
	docker build -t $(DOCKER_TAG_LATEST) -t $(DOCKER_TAG_SHA) .
	@echo "✅ Local build completed and loaded to Docker daemon!"

docker-build-multi-push: docker-setup-buildx ## Build multi-platform image and push to registry
	@echo "🏗️  Building multi-platform Docker image with push..."
	@echo "📦 Platforms: linux/amd64, linux/arm64"
	@echo "📦 Tags: $(DOCKER_TAG_LATEST), $(DOCKER_TAG_SHA)"
	docker buildx build \
		--platform linux/amd64,linux/arm64 \
		-t $(DOCKER_TAG_LATEST) \
		-t $(DOCKER_TAG_SHA) \
		--push \
		.
	@echo "✅ Multi-platform build completed and pushed!"

docker-push: ## Push Docker images to registry
	@echo "🚀 Pushing Docker images..."
	docker push $(DOCKER_TAG_LATEST)
	docker push $(DOCKER_TAG_SHA)
	@echo "✅ Images pushed successfully!"

docker-info: ## Show Docker build information
	@echo "📋 Docker Build Information:"
	@echo "  Image name: $(DOCKER_IMAGE_NAME)"
	@echo "  Git SHA: $(GIT_SHA)"
	@echo "  Latest tag: $(DOCKER_TAG_LATEST)"
	@echo "  SHA tag: $(DOCKER_TAG_SHA)"