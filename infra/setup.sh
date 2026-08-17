#!/bin/bash
set -euo pipefail

INFRA_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "$INFRA_DIR/.." && pwd)"

echo "== Vérification de Docker =="
if ! command -v docker >/dev/null 2>&1; then
    echo "Docker n'est pas installé. Installe-le avant de continuer : https://docs.docker.com/engine/install/"
    exit 1
fi
if ! docker compose version >/dev/null 2>&1; then
    echo "Le plugin 'docker compose' n'est pas disponible."
    exit 1
fi

echo "== Secrets infra (infra/.env) =="
if [ ! -f "$INFRA_DIR/.env" ]; then
    DB_PASSWORD=$(openssl rand -hex 16)
    cat > "$INFRA_DIR/.env" <<EOF
POSTGRES_DB=yzendraid_db
POSTGRES_USER=yzendraid_app
POSTGRES_PASSWORD=$DB_PASSWORD
EOF
    echo "infra/.env généré avec un mot de passe DB aléatoire."
else
    echo "infra/.env existe déjà, inchangé."
fi

set -a
source "$INFRA_DIR/.env"
set +a

echo "== Secrets applicatifs (app/.env.local) =="
if [ ! -f "$APP_DIR/.env.local" ]; then
    APP_SECRET=$(openssl rand -hex 16)
    JWT_PASSPHRASE=$(openssl rand -hex 16)
    cat > "$APP_DIR/.env.local" <<EOF
APP_SECRET=$APP_SECRET
DATABASE_URL="postgresql://${POSTGRES_USER}:${POSTGRES_PASSWORD}@postgres:5432/${POSTGRES_DB}?serverVersion=16&charset=utf8"
JWT_PASSPHRASE=$JWT_PASSPHRASE
EOF
    echo "app/.env.local généré."
else
    echo "app/.env.local existe déjà, inchangé."
fi

echo "== Démarrage des conteneurs =="
(cd "$INFRA_DIR" && docker compose up -d --build)

echo "== Installation des dépendances PHP =="
(cd "$INFRA_DIR" && docker compose exec -T php composer install --no-interaction --optimize-autoloader)

echo "== Clés JWT =="
if [ ! -f "$APP_DIR/config/jwt/private.pem" ]; then
    JWT_PASSPHRASE=$(grep -E '^JWT_PASSPHRASE=' "$APP_DIR/.env.local" | cut -d= -f2-)
    (cd "$INFRA_DIR" && docker compose exec -T -e JWT_PASSPHRASE="$JWT_PASSPHRASE" php php bin/console lexik:jwt:generate-keypair --no-interaction)
    echo "Paire de clés JWT générée dans app/config/jwt/."
    echo "IMPORTANT : copier app/config/jwt/public.pem vers toute app qui doit"
    echo "vérifier les tokens émis par ce service (ex. Equi en AUTH_MODE=central)."
else
    echo "app/config/jwt/private.pem existe déjà, inchangé."
fi

echo "== Attente de PostgreSQL =="
until (cd "$INFRA_DIR" && docker compose exec -T postgres pg_isready -U "$POSTGRES_USER" >/dev/null 2>&1); do
    sleep 1
done

echo "== Migrations Doctrine =="
(cd "$INFRA_DIR" && docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction)

cat <<'EOF'

=== Terminé ! ===
Voir docs/API.md pour la doc des routes.
Reste manuel : distribuer config/jwt/public.pem aux apps qui doivent
faire confiance aux tokens de ce service (pas d'endpoint JWKS pour
l'instant, tout est sur le même serveur).
EOF
