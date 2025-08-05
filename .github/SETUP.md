# Configuration CI/CD - Guide de Setup

## 🐳 Configuration Docker Hub

### 1. Créer un compte Docker Hub
- Allez sur https://hub.docker.com/
- Créez un compte si vous n'en avez pas

### 2. Créer un Access Token
- Connectez-vous à Docker Hub
- Allez dans **Account Settings** → **Security**
- Cliquez sur **New Access Token**
- Nom : `github-actions-onlyflooze`
- Permissions : **Read, Write, Delete**
- **Copiez le token généré** (vous ne pourrez plus le voir après)

### 3. Créer le repository
- Allez dans **Repositories** → **Create Repository**
- Nom : `onlyflooze-sf`
- Visibilité : **Public** ou **Private** (selon vos besoins)

## 🐙 Configuration GitHub

### 1. Créer les Secrets
Dans votre repository GitHub, allez dans **Settings** → **Secrets and variables** → **Actions** :

#### Secrets à créer :
- **DOCKERHUB_USERNAME** : votre nom d'utilisateur Docker Hub
- **DOCKERHUB_TOKEN** : le token créé précédemment

### 2. Modifier le workflow
Dans le fichier `.github/workflows/ci-cd.yml`, changez :
```yaml
env:
  IMAGE_NAME: votre-username/onlyflooze-sf
```
Remplacez `votre-username` par votre nom d'utilisateur Docker Hub.

### 3. Activer GitHub Actions (si nécessaire)
- Allez dans **Actions** de votre repository
- Si les Actions sont désactivées, cliquez sur **Enable**

## 🚀 Test du Pipeline

### 1. Premier test
Poussez du code sur la branche `main` :
```bash
git add .
git commit -m "Add CI/CD pipeline"
git push origin main
```

### 2. Vérifications
- Allez dans l'onglet **Actions** de votre repository GitHub
- Vérifiez que le workflow se lance automatiquement
- Les étapes doivent être :
  1. ✅ PHPStan Analysis & Tests
  2. ✅ Build and Push Docker Images
  3. ✅ Security Scan (optionnel)

### 3. Vérifier sur Docker Hub
- Allez sur https://hub.docker.com/r/votre-username/onlyflooze-sf
- Vous devriez voir votre image avec les tags :
  - `latest`
  - `main-{sha}`

## 🏷️ Tags automatiques

Le workflow crée automatiquement plusieurs tags :
- `latest` : pour la branche main
- `main-{sha}` : branche + SHA du commit
- `v1.0.0` : pour les releases GitHub (si vous créez des releases)

## ⚙️ Déclencheurs du Pipeline

Le pipeline se déclenche automatiquement :
- ✅ **Push sur main** : Tests + Build + Push
- ✅ **Pull Request** : Tests seulement (pas de push)
- ✅ **Release GitHub** : Tests + Build + Push avec tag de version

## 🔒 Sécurité

### Bonnes pratiques activées :
- ✅ Scan de vulnérabilités avec Trivy
- ✅ Cache optimisé pour les builds
- ✅ Secrets protégés
- ✅ Build multi-plateforme (AMD64 + ARM64)

### Permissions minimales :
- Le token Docker Hub a seulement les permissions nécessaires
- Les secrets GitHub sont protégés et non exposés dans les logs

## 🛠️ Personnalisation

### Ajouter d'autres tests :
Modifiez la section `test` dans `.github/workflows/ci-cd.yml` :
```yaml
- name: Run additional tests
  run: |
    # Vos tests supplémentaires
    make lint
    make security-check
```

### Changer les plateformes de build :
```yaml
platforms: linux/amd64,linux/arm64,linux/arm/v7
```

### Notifications :
Ajoutez des notifications Slack/Discord/Teams selon vos besoins.

## 🐛 Troubleshooting

### Erreur "unauthorized" :
- Vérifiez vos secrets DOCKERHUB_USERNAME et DOCKERHUB_TOKEN
- Vérifiez que le token n'a pas expiré

### Tests échouent :
- Vérifiez la configuration de la base de données de test
- Vérifiez que PHPStan passe en local

### Build échoue :
- Vérifiez le Dockerfile
- Consultez les logs détaillés dans l'onglet Actions