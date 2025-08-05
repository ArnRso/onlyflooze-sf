# Déploiement Docker avec FrankenPHP

Cette configuration utilise FrankenPHP pour servir l'application Symfony avec des performances optimales.

## 🚀 Démarrage rapide

```bash
# Cloner le projet
git clone <votre-repo>
cd onlyflooze_sf

# Configurer les variables d'environnement
cp .env.prod .env.local
# Éditez .env.local pour définir APP_SECRET et autres variables

# Démarrer l'application
docker compose up -d

# L'application sera disponible sur http://localhost
```

## 📋 Configuration requise

- Docker et Docker Compose
- Ports 80 et 443 disponibles

## 🔧 Configuration

### Variables d'environnement importantes

Éditez `.env.local` ou utilisez des variables d'environnement :

```bash
# Secret de l'application (OBLIGATOIRE en production)
APP_SECRET=votre-secret-unique-de-32-caracteres

# Base de données
POSTGRES_DB=votre_db
POSTGRES_USER=votre_user  
POSTGRES_PASSWORD=votre_password_securise

# Environnement
APP_ENV=prod
APP_DEBUG=0
```

### HTTPS/SSL

FrankenPHP peut gérer automatiquement les certificats Let's Encrypt :

1. Modifiez `docker/Caddyfile`
2. Remplacez `:80` par votre domaine : `votre-domaine.com`
3. Supprimez `auto_https off`

## 🏗️ Architecture

```
┌─────────────────┐    ┌──────────────────┐
│   FrankenPHP    │────│   PostgreSQL     │
│  (Web + PHP)    │    │   (Database)     │
│   Port 80/443   │    │   Port 5432      │
└─────────────────┘    └──────────────────┘
```

### Avantages de FrankenPHP

- **Performance** : Worker mode garde Symfony en mémoire
- **Simplicité** : Un seul conteneur pour web + PHP
- **Moderne** : HTTP/2, HTTP/3, WebSockets
- **Sécurité** : HTTPS automatique avec Let's Encrypt

## 🛠️ Commandes utiles

```bash
# Voir les logs
docker compose logs -f web

# Accéder au conteneur
docker compose exec web bash

# Exécuter des commandes Symfony
docker compose exec web php bin/console cache:clear
docker compose exec web php bin/console doctrine:migrations:migrate

# Redémarrer l'application
docker compose restart web

# Arrêter tout
docker compose down

# Reconstruction complète
docker compose down
docker compose build --no-cache
docker compose up -d
```

## 📁 Structure des fichiers Docker

```
docker/
├── Caddyfile          # Configuration FrankenPHP/Caddy
├── entrypoint.sh      # Script d'initialisation
└── README.md          # Cette documentation

Dockerfile             # Image principale
.dockerignore         # Fichiers exclus du build
.env.prod            # Configuration de production
compose.yaml         # Orchestration des services
```

## 🔍 Debugging

### Vérifier la santé des services

```bash
# Status des conteneurs
docker compose ps

# Logs détaillés
docker compose logs web
docker compose logs database

# Health checks
docker compose exec web curl -f http://localhost/health
```

### Problèmes courants

1. **Port déjà utilisé** : Changez les ports dans `compose.yaml`
2. **Permissions** : Vérifiez les permissions des dossiers `var/`
3. **Database connexion** : Vérifiez les variables d'environnement
4. **Cache** : Effacez le cache avec `docker compose exec web php bin/console cache:clear`

## 🚢 Production

### Checklist avant déploiement

- [ ] Changez `APP_SECRET` dans `.env.local`
- [ ] Configurez un mot de passe fort pour PostgreSQL
- [ ] Activez HTTPS avec votre domaine dans `Caddyfile`
- [ ] Configurez les backups de base de données
- [ ] Vérifiez les logs et monitoring
- [ ] Testez les migrations et rollbacks

### Mise à jour

```bash
# Sauvegarder la base de données
docker compose exec database pg_dump -U app app > backup.sql

# Mettre à jour
git pull
docker compose build --no-cache
docker compose up -d

# Vérifier
docker compose logs -f web
```

## 📊 Monitoring

L'application expose plusieurs endpoints :

- `/health` : Health check
- Logs JSON dans stdout pour intégration avec votre stack de monitoring

## 🤝 Support

Pour les problèmes liés à :
- FrankenPHP : https://frankenphp.dev/
- Symfony : https://symfony.com/doc
- Docker : https://docs.docker.com/