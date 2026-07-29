#!/bin/sh
set -e

echo "🚀 Démarrage de l'application (FrankenPHP)..."

wait_for_db() {
    echo "⏳ Attente de la base de données..."
    attempt=1
    max_attempts=30
    until php bin/console dbal:run-sql "SELECT 1" > /dev/null 2>&1; do
        if [ "$attempt" -ge "$max_attempts" ]; then
            echo "❌ Base de données injoignable après $max_attempts tentatives"
            php bin/console dbal:run-sql "SELECT 1" 2>&1 | head -5 || true
            exit 1
        fi
        attempt=$((attempt + 1))
        sleep 2
    done
    echo "✅ Base de données disponible"
}

run_migrations() {
    echo "🔄 Exécution des migrations..."
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
    echo "✅ Migrations terminées"
}

wait_for_db
run_migrations

echo "🎉 Application prête, démarrage de FrankenPHP"
exec "$@"
