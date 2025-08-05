# =============================================================================
# STAGE 1: Builder - Installation des dépendances et compilation
# =============================================================================
FROM dunglas/frankenphp:1-php8.3 AS builder

# Installe les dépendances système nécessaires pour le build
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libicu-dev \
    && rm -rf /var/lib/apt/lists/*

# Installe les extensions PHP requises par Symfony
RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    intl \
    opcache \
    zip \
    gd \
    exif

# Installe Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Définit le répertoire de travail
WORKDIR /app

# Copie les fichiers de configuration pour optimiser le cache Docker
COPY composer.json composer.lock symfony.lock ./

# Installe les dépendances PHP de production
RUN composer install \
    --no-scripts \
    --no-dev \
    --optimize-autoloader \
    --classmap-authoritative \
    && composer clear-cache

# Copie le code source complet
COPY . .

# Génère l'autoloader optimisé avec le code complet
RUN composer dump-autoload --optimize --classmap-authoritative

# Copie la configuration d'environnement de production
COPY .env.prod .env.local

# Installe les assets de l'importmap (Bootstrap, etc.)
RUN php bin/console importmap:install

# Compile les assets Symfony
RUN php bin/console asset-map:compile --env=prod

# Réchauffe le cache de production
RUN php bin/console cache:warmup --env=prod

# Nettoie les fichiers temporaires et caches inutiles
RUN rm -rf \
    /tmp/* \
    /var/tmp/* \
    .env.local \
    /root/.composer/cache

# =============================================================================
# STAGE 2: Runtime - Image finale légère pour la production
# =============================================================================
FROM dunglas/frankenphp:1-php8.3-alpine AS runtime

# Installe seulement les dépendances runtime nécessaires (Alpine packages)
RUN apk add --no-cache \
    postgresql-client \
    icu-libs \
    netcat-openbsd \
    bind-tools \
    && rm -rf /var/cache/apk/*

# Installe les extensions PHP nécessaires (même liste que builder)
RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    intl \
    opcache \
    zip \
    gd \
    exif

# Configure PHP pour la production
COPY docker/php-prod.ini /usr/local/etc/php/conf.d/zzz-prod.ini

# Crée un utilisateur non-root
RUN addgroup -g 1000 symfony && adduser -u 1000 -G symfony -s /bin/sh -D symfony

# Crée les répertoires nécessaires avec les bonnes permissions
RUN mkdir -p /app/var/cache /app/var/log /app/var/sessions /app/var/uploads \
    && chown -R symfony:symfony /app/var \
    && chmod -R 755 /app/var

# Définit le répertoire de travail
WORKDIR /app

# Copie uniquement les fichiers nécessaires depuis le stage builder
COPY --from=builder --chown=symfony:symfony /app/vendor ./vendor
COPY --from=builder --chown=symfony:symfony /app/public ./public
COPY --from=builder --chown=symfony:symfony /app/src ./src
COPY --from=builder --chown=symfony:symfony /app/config ./config
COPY --from=builder --chown=symfony:symfony /app/templates ./templates
COPY --from=builder --chown=symfony:symfony /app/translations ./translations
COPY --from=builder --chown=symfony:symfony /app/migrations ./migrations
COPY --from=builder --chown=symfony:symfony /app/bin ./bin
COPY --from=builder --chown=symfony:symfony /app/var/cache ./var/cache

# Copie les fichiers de configuration essentiels
COPY --chown=symfony:symfony composer.json composer.lock symfony.lock ./
COPY --chown=symfony:symfony .env.prod .env.local

# Copie les fichiers Docker
COPY --chown=root:root docker/Caddyfile /etc/caddy/Caddyfile
COPY --chown=root:root docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Bascule vers l'utilisateur non-root
USER symfony

# Expose les ports
EXPOSE 80 443

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

# Utilise le script d'entrypoint
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# Commande par défaut : démarre FrankenPHP avec worker mode
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]