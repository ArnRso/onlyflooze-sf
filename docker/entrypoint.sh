#!/bin/sh
set -e

echo "🚀 Démarrage de l'application Symfony avec FrankenPHP..."

# Fonction pour extraire le hostname depuis DATABASE_URL
extract_db_host() {
    if [ -n "${DATABASE_URL-}" ]; then
        # Extrait le hostname depuis postgresql://user:pass@HOST:port/db
        echo "${DATABASE_URL}" | sed -n 's|.*://[^@]*@\([^:]*\):.*|\1|p'
    else
        echo "database"  # fallback par défaut
    fi
}

# Fonction pour attendre que la base de données soit prête
wait_for_db() {
    echo "⏳ Attente de la disponibilité de la base de données..."
    echo "🔍 Debug: Variables d'environnement DB:"
    echo "   - DATABASE_URL: ${DATABASE_URL-non définie}"
    echo "   - APP_ENV: ${APP_ENV-non définie}"
    
    local db_host=$(extract_db_host)
    local db_user=${POSTGRES_USER-app}
    
    echo "🔍 Configuration extraite:"
    echo "   - DB Host: $db_host"
    echo "   - DB User: $db_user"
    
    local attempt=1
    local max_attempts=30
    
    until pg_isready -h $db_host -p 5432 -U $db_user > /dev/null 2>&1; do
        echo "⏳ Tentative $attempt/$max_attempts - Base de données non disponible..."
        
        # Debug détaillé toutes les 5 tentatives
        if [ $((attempt % 5)) -eq 0 ]; then
            echo "🔍 Debug détaillé (tentative $attempt):"
            echo "   - Test de résolution DNS:"
            nslookup $db_host 2>/dev/null || echo "     DNS: échec"
            
            echo "   - Test de connexion réseau:"
            nc -z $db_host 5432 2>/dev/null && echo "     Port 5432: ouvert" || echo "     Port 5432: fermé"
            
            echo "   - Test pg_isready:"
            pg_isready -h $db_host -p 5432 -U $db_user 2>/dev/null && echo "     PostgreSQL: prêt" || echo "     PostgreSQL: pas prêt"
            
            echo "   - Test Doctrine (avec erreurs):"
            php bin/console doctrine:query:sql "SELECT 1" 2>&1 | head -3
            
            echo "   - Cache Symfony:"
            ls -la var/cache/ 2>/dev/null | head -3 || echo "     Cache: non accessible"
        fi
        
        sleep 2
        attempt=$((attempt + 1))
        
        if [ $attempt -gt $max_attempts ]; then
            echo "❌ Échec: Impossible de se connecter à la base de données après $max_attempts tentatives"
            echo "🔍 Diagnostic final:"
            echo "   - Variables d'environnement:"
            env | grep -E "(DATABASE_URL|POSTGRES_|APP_)" || echo "     Aucune variable DB trouvées"
            echo "   - Configuration extraite: Host=$db_host, User=$db_user"
            echo "   - Dernière erreur Doctrine:"
            php bin/console doctrine:query:sql "SELECT 1" 2>&1 || true
            exit 1
        fi
    done
    echo "✅ Base de données disponible après $attempt tentatives !"
}

# Fonction pour exécuter les migrations
run_migrations() {
    echo "🔄 Vérification et exécution des migrations..."
    
    # Vérifie si des migrations sont en attente
    if php bin/console doctrine:migrations:up-to-date --no-interaction 2>/dev/null; then
        echo "✅ Base de données à jour"
    else
        echo "📦 Exécution des migrations..."
        if php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration 2>&1; then
            echo "✅ Migrations terminées"
        else
            echo "⚠️  Attention: Erreur lors des migrations, mais on continue..."
            echo "   (Les migrations peuvent échouer si elles sont déjà appliquées)"
        fi
    fi
}

# Fonction pour chauffer le cache
warm_cache() {
    echo "🔥 Réchauffement du cache..."
    if php bin/console cache:warmup --env=prod 2>&1; then
        echo "✅ Cache réchauffé"
    else
        echo "⚠️  Erreur cache warmup, mais on continue..."
    fi
}

# Fonction pour compiler les assets
compile_assets() {
    echo "📦 Compilation des assets..."
    if php bin/console asset-map:compile --env=prod 2>&1; then
        echo "✅ Assets compilés"
    else
        echo "⚠️  Erreur compilation assets, mais on continue..."
    fi
}

# Fonction pour optimiser Composer (déjà fait au build)
optimize_autoloader() {
    echo "⚡ Autoloader déjà optimisé au build"
}

# Fonction pour créer les répertoires nécessaires
create_directories() {
    echo "📁 Création des répertoires nécessaires..."
    mkdir -p /app/var/cache
    mkdir -p /app/var/log
    mkdir -p /app/var/uploads
    mkdir -p /app/var/sessions
    
    # Assure les bonnes permissions
    chmod -R 755 /app/var
    echo "✅ Répertoires créés"
}

# Fonction pour vérifier la configuration
check_config() {
    echo "🔍 Vérification de la configuration..."
    
    if [ -z "$APP_SECRET" ] || [ "$APP_SECRET" = "CHANGE_ME_IN_PRODUCTION_9b6c4c8f2a7d8e1f3a5c9e2b8d6f4a1c" ]; then
        echo "⚠️  ATTENTION: APP_SECRET n'est pas défini ou utilise la valeur par défaut !"
        echo "   Définissez une valeur unique pour APP_SECRET en production."
    fi
    
    echo "✅ Configuration vérifiée"
}

# Exécution des étapes d'initialisation
main() {
    echo "🐘 Initialisation de l'application Symfony..."
    
    # Création des répertoires
    create_directories
    
    # Vérification de la configuration
    check_config
    
    # Optimisation de l'autoloader
    echo "🔍 DEBUG: Début optimize_autoloader"
    optimize_autoloader
    echo "🔍 DEBUG: Fin optimize_autoloader"
    
    # Attente de la base de données
    echo "🔍 DEBUG: Début wait_for_db"
    wait_for_db
    echo "🔍 DEBUG: Fin wait_for_db"
    
    # Exécution des migrations
    echo "🔍 DEBUG: Début run_migrations"
    run_migrations
    echo "🔍 DEBUG: Fin run_migrations"
    
    # Compilation des assets
    echo "🔍 DEBUG: Début compile_assets"
    compile_assets
    echo "🔍 DEBUG: Fin compile_assets"
    
    # Réchauffement du cache
    echo "🔍 DEBUG: Début warm_cache"
    warm_cache
    echo "🔍 DEBUG: Fin warm_cache"
    
    # Affichage des informations de version
    echo "📋 Informations système:"
    echo "   - PHP: $(php -v | head -n1)"
    echo "   - Symfony: $(php bin/console --version)"
    echo "   - Environment: ${APP_ENV-prod}"
    echo "   - Debug: ${APP_DEBUG-0}"
    
    echo "🎉 Application initialisée avec succès !"
    echo "🚀 Démarrage de Nginx + PHP-FPM..."
    echo "🔍 Commande à exécuter: $@"
    
    # Prépare les répertoires pour PHP-FPM
    mkdir -p /var/run/php
    chown www-data:www-data /var/run/php
    
    # Prépare les logs
    mkdir -p /var/log/supervisor
    touch /var/log/php-fpm-error.log /var/log/php-fpm-slow.log
    chown www-data:www-data /var/log/php-fpm-*.log
    
    # Exécute la commande passée en paramètre (Supervisor)
    echo "📋 Avant exec - PID: $$"
    exec "$@"
}

# Gestion des signaux pour un arrêt propre
trap 'echo "🛑 Arrêt de l'\''application..."; exit 0' SIGTERM SIGINT

# Lancer main directement (pas de sourcing en Docker)
main "$@"