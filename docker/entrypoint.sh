#!/bin/bash
set -e

echo "🚀 Démarrage de l'application Symfony avec FrankenPHP..."

# Fonction pour attendre que la base de données soit prête
wait_for_db() {
    echo "⏳ Attente de la disponibilité de la base de données..."
    until php bin/console doctrine:query:sql "SELECT 1" > /dev/null 2>&1; do
        echo "⏳ Base de données non disponible, nouvelle tentative dans 2 secondes..."
        sleep 2
    done
    echo "✅ Base de données disponible !"
}

# Fonction pour exécuter les migrations
run_migrations() {
    echo "🔄 Vérification et exécution des migrations..."
    
    # Vérifie si des migrations sont en attente
    if php bin/console doctrine:migrations:up-to-date --no-interaction; then
        echo "✅ Base de données à jour"
    else
        echo "📦 Exécution des migrations..."
        php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
        echo "✅ Migrations terminées"
    fi
}

# Fonction pour chauffer le cache
warm_cache() {
    echo "🔥 Réchauffement du cache..."
    php bin/console cache:warmup --env=prod
    echo "✅ Cache réchauffé"
}

# Fonction pour compiler les assets
compile_assets() {
    echo "📦 Compilation des assets..."
    php bin/console asset-map:compile --env=prod
    echo "✅ Assets compilés"
}

# Fonction pour optimiser Composer
optimize_autoloader() {
    echo "⚡ Optimisation de l'autoloader Composer..."
    composer dump-autoload --optimize --no-dev --classmap-authoritative
    echo "✅ Autoloader optimisé"
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
    optimize_autoloader
    
    # Attente de la base de données
    wait_for_db
    
    # Exécution des migrations
    run_migrations
    
    # Compilation des assets
    compile_assets
    
    # Réchauffement du cache
    warm_cache
    
    # Affichage des informations de version
    echo "📋 Informations système:"
    echo "   - PHP: $(php -v | head -n1)"
    echo "   - Symfony: $(php bin/console --version)"
    echo "   - Environment: ${APP_ENV:-prod}"
    echo "   - Debug: ${APP_DEBUG:-0}"
    
    echo "🎉 Application initialisée avec succès !"
    echo "🚀 Démarrage de FrankenPHP..."
    
    # Exécute la commande passée en paramètre
    exec "$@"
}

# Gestion des signaux pour un arrêt propre
trap 'echo "🛑 Arrêt de l'\''application..."; exit 0' SIGTERM SIGINT

# Si le script est exécuté directement, lancer main
if [ "${BASH_SOURCE[0]}" == "${0}" ]; then
    main "$@"
fi