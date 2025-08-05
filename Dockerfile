# =============================================================================
# STAGE 1: Builder - Installation des dépendances et compilation
# =============================================================================
FROM php:8.3-fpm AS builder

# Installe les dépendances système nécessaires pour le build
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libicu-dev \
    && rm -rf /var/lib/apt/lists/*

# Installe les extensions PHP requises par Symfony
RUN apt-get install -y \
    libpq-dev \
    libicu-dev \
    libzip-dev \
    libjpeg-dev \
    libpng-dev \
    libwebp-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
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
# STAGE 2: Runtime - Nginx + PHP-FPM pour la production
# =============================================================================
FROM php:8.3-fpm AS runtime

# Installe Nginx et les dépendances nécessaires
RUN apt-get update && apt-get install -y \
    nginx \
    postgresql-client \
    libpq-dev \
    libicu-dev \
    libzip-dev \
    libjpeg-dev \
    libpng-dev \
    libwebp-dev \
    libfreetype6-dev \
    supervisor \
    curl \
    netcat-openbsd \
    dnsutils \
    && rm -rf /var/lib/apt/lists/*

# Installe les extensions PHP (même liste que builder)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
    pdo_pgsql \
    pgsql \
    intl \
    opcache \
    zip \
    gd \
    exif

# Configure PHP pour la production
COPY docker/php-prod.ini /usr/local/etc/php/conf.d/zzz-prod.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

# Configure Nginx
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/default.conf /etc/nginx/sites-available/default
RUN rm -f /etc/nginx/sites-enabled/default \
    && ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# Configure Supervisor pour gérer Nginx + PHP-FPM
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Crée un utilisateur non-root
RUN groupadd -r symfony && useradd -r -g symfony symfony

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
COPY --chown=root:root docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Configure les permissions Nginx
RUN chown -R www-data:www-data /var/log/nginx \
    && chown -R www-data:www-data /var/lib/nginx \
    && chown -R www-data:www-data /run

# Expose le port HTTP
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

# Utilise le script d'entrypoint
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

# Commande par défaut : démarre Supervisor (Nginx + PHP-FPM)
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf", "-n"]