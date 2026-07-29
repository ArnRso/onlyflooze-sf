#!/bin/bash
set -e  # Arrêter en cas d'erreur

# Configuration
SSH_ALIAS="home-arnrso"
REMOTE_CONTAINER="onlyflooze_db"
LOCAL_CONTAINER="onlyflooze_db"
DUMP_FILE="prod_dump_$(date +%Y%m%d_%H%M%S).sql"
TEMP_DIR="/tmp"

# Options
SKIP_CONFIRMATION=false

# Couleurs pour les messages
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Afficher l'aide
show_help() {
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "Synchronise la base de données de production vers l'environnement local."
    echo ""
    echo "Options:"
    echo "  -y, --yes     Exécuter sans demander de confirmation"
    echo "  -h, --help    Afficher cette aide"
    echo ""
    echo "Exemple:"
    echo "  $0           # Mode interactif (demande confirmation)"
    echo "  $0 -y        # Mode non-interactif (pas de confirmation)"
    exit 0
}

# Parser les arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        -y|--yes)
            SKIP_CONFIRMATION=true
            shift
            ;;
        -h|--help)
            show_help
            ;;
        *)
            echo -e "${RED}Option inconnue: $1${NC}"
            show_help
            ;;
    esac
done

echo -e "${GREEN}=== Synchronisation de la base de données de production ===${NC}\n"

# Récupérer les variables d'environnement depuis .env.local ou .env
if [ -f .env.local ]; then
    export $(grep -v '^#' .env.local | grep -E '^(POSTGRES_|DATABASE_URL)' | xargs)
fi
if [ -f .env ]; then
    export $(grep -v '^#' .env | grep -E '^(POSTGRES_|DATABASE_URL)' | xargs)
fi

# Valeurs par défaut si non définies
POSTGRES_DB=${POSTGRES_DB:-app}
POSTGRES_USER=${POSTGRES_USER:-app}
POSTGRES_PASSWORD=${POSTGRES_PASSWORD:-!ChangeMe!}

echo -e "${YELLOW}1. Vérification du container local...${NC}"
if ! docker ps | grep -q "$LOCAL_CONTAINER"; then
    echo -e "${RED}Erreur: Le container local '$LOCAL_CONTAINER' n'est pas démarré.${NC}"
    echo "Exécutez: docker compose up -d"
    exit 1
fi
echo -e "${GREEN}✓ Container local actif${NC}\n"

echo -e "${YELLOW}2. Création du dump de la base de production...${NC}"
ssh $SSH_ALIAS "docker exec $REMOTE_CONTAINER pg_dump -U $POSTGRES_USER -d $POSTGRES_DB --clean --if-exists" > "$TEMP_DIR/$DUMP_FILE"

if [ ! -s "$TEMP_DIR/$DUMP_FILE" ]; then
    echo -e "${RED}Erreur: Le dump est vide ou a échoué${NC}"
    rm -f "$TEMP_DIR/$DUMP_FILE"
    exit 1
fi

DUMP_SIZE=$(du -h "$TEMP_DIR/$DUMP_FILE" | cut -f1)
echo -e "${GREEN}✓ Dump créé: $DUMP_FILE ($DUMP_SIZE)${NC}\n"

echo -e "${YELLOW}3. Restauration de la base de données locale...${NC}"
if [ "$SKIP_CONFIRMATION" = false ]; then
    echo -e "${YELLOW}   ⚠️  Cela va ÉCRASER toutes les données locales !${NC}"
    read -p "Continuer ? (o/N) " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Oo]$ ]]; then
        echo -e "${RED}Annulé par l'utilisateur${NC}"
        rm -f "$TEMP_DIR/$DUMP_FILE"
        exit 0
    fi
else
    echo -e "${YELLOW}   Mode non-interactif : restauration automatique${NC}"
fi

# Restauration du dump
cat "$TEMP_DIR/$DUMP_FILE" | docker exec -i $LOCAL_CONTAINER psql -U $POSTGRES_USER -d $POSTGRES_DB

echo -e "\n${GREEN}✓ Base de données restaurée avec succès${NC}\n"

# Nettoyage
echo -e "${YELLOW}4. Nettoyage...${NC}"
rm -f "$TEMP_DIR/$DUMP_FILE"
echo -e "${GREEN}✓ Fichier temporaire supprimé${NC}\n"

echo -e "${GREEN}=== Synchronisation terminée avec succès ===${NC}"
echo -e "Base de données: ${GREEN}$POSTGRES_DB${NC}"
echo -e "Utilisateur: ${GREEN}$POSTGRES_USER${NC}"
