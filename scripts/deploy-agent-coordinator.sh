#!/usr/bin/env bash
# Run from Lucas's usual SSH session: bash scripts/deploy-agent-coordinator.sh <reviewed-commit-sha>
# Publishes inert code. ALPESEX_MCP_ENABLED must be configured separately after review.
set -Eeuo pipefail
umask 077

revision=${1:-}
if [[ ! $revision =~ ^[0-9a-f]{40}$ ]]; then
  echo 'Révision Git complète requise.' >&2
  exit 1
fi

repository=/home/www/repository
public_root=/home/www/public
app_root=/home/www/app
private_env=/home/www/private/.env
base=5fb82ac71859fb52d47fc7c7341ed3fdc3dd458b

cd "$repository"
git fetch origin feature/agent-cockpit
git cat-file -e "${revision}^{commit}"
git merge-base --is-ancestor "$base" "$revision"

backup=$(mktemp -d /home/www/backups/agent-coordinator-XXXXXXXX)
chmod 700 "$backup"
mkdir -m 700 "$backup/stage" "$backup/previous"

files=(
  .well-known/.htaccess
  .well-known/oauth-protected-resource
  .well-known/oauth-authorization-server
  admin-agents/agents.js
  server/public/api/admin/agents/index.php
  server/public/api/admin/agents/mcp/index.php
  server/public/api/admin/agents/oauth/index.php
  server/src/AgentCockpit/Gateway.php
  server/src/AgentCockpit/OAuth.php
  server/bin/archive-agent-test.php
  server/bin/revoke-agent-tokens.php
  server/migrations/017_agent_coordinator_gateway.sql
)

target() {
  case "$1" in
    server/public/*) printf '%s/%s' "$public_root" "${1#server/public/}" ;;
    server/src/*) printf '%s/%s' "$app_root" "${1#server/}" ;;
    server/bin/*) printf '%s/%s' "$app_root" "${1#server/}" ;;
    server/migrations/*) printf '%s/%s' "$app_root" "${1#server/}" ;;
    *) printf '%s/%s' "$public_root" "$1" ;;
  esac
}

for file in "${files[@]}"; do
  mkdir -p "$backup/stage/$(dirname "$file")"
  git show "$revision:$file" > "$backup/stage/$file"
  test -s "$backup/stage/$file"
  destination=$(target "$file")
  if [ -f "$destination" ]; then
    if git cat-file -e "$base:$file" 2>/dev/null; then
      git show "$base:$file" > "$backup/stage/expected"
      if ! cmp -s "$backup/stage/expected" "$destination" && ! cmp -s "$backup/stage/$file" "$destination"; then
        echo "Fichier de production inattendu : $destination" >&2
        exit 1
      fi
    elif ! cmp -s "$backup/stage/$file" "$destination"; then
      echo "Fichier ajouté localement en production : $destination" >&2
      exit 1
    fi
    mkdir -p "$backup/previous/$(dirname "$file")"
    cp -p "$destination" "$backup/previous/$file"
  fi
done

git show "$revision:server/bin/backup-agent-database.php" > "$backup/stage/backup-agent-database.php"
test -s "$backup/stage/backup-agent-database.php"
for file in "$backup/stage/backup-agent-database.php" \
  "$backup/stage/server/public/api/admin/agents/index.php" \
  "$backup/stage/server/public/api/admin/agents/mcp/index.php" \
  "$backup/stage/server/public/api/admin/agents/oauth/index.php" \
  "$backup/stage/server/src/AgentCockpit/Gateway.php" \
  "$backup/stage/server/src/AgentCockpit/OAuth.php" \
  "$backup/stage/server/bin/archive-agent-test.php" \
  "$backup/stage/server/bin/revoke-agent-tokens.php"; do
  php -l "$file" >/dev/null
done
node --check "$backup/stage/admin-agents/agents.js"

if [ ! -f "$private_env" ]; then
  echo 'Configuration privée absente.' >&2
  exit 1
fi
if ALPESEX_APP_DIR="$app_root" php -r '$services=require getenv("ALPESEX_APP_DIR")."/bootstrap.php"; if (($_ENV["ALPESEX_MCP_ENABLED"] ?? "") === "1") exit(1);'; then
  :
else
  echo 'Passerelle déjà active : désactivation préalable requise.' >&2
  exit 1
fi
ALPESEX_APP_DIR="$app_root" php "$backup/stage/backup-agent-database.php" "$backup"
test -s "$backup/database.sql"

rollback_files() {
  trap - ERR
  for file in "${files[@]}"; do
    destination=$(target "$file")
    if [ -f "$backup/previous/$file" ]; then
      mkdir -p "$(dirname "$destination")"
      cp -p "$backup/previous/$file" "$destination"
    else
      rm -f -- "$destination"
    fi
  done
  echo "Échec pendant : $phase" >&2
  echo "Publication interrompue. Fichiers restaurés. Sauvegarde DB conservée : $backup" >&2
  exit 1
}
phase='installation de la migration 017'
trap rollback_files ERR

install -m 0644 "$backup/stage/server/migrations/017_agent_coordinator_gateway.sql" \
  "$app_root/migrations/017_agent_coordinator_gateway.sql"
phase='exécution des migrations'
php "$app_root/bin/migrate.php"

for file in "${files[@]}"; do
  if [ "$file" = server/migrations/017_agent_coordinator_gateway.sql ]; then
    continue
  fi
  phase="publication de $file"
  destination=$(target "$file")
  mkdir -p "$(dirname "$destination")"
  install -m 0644 "$backup/stage/$file" "$destination"
  cmp -s "$backup/stage/$file" "$destination"
done

phase='contrôle HTTP de l’API agents'
api_status=$(curl -q -sS --proto '=https' --max-time 20 -o /dev/null -w '%{http_code}' https://alpes-ex.fr/api/admin/agents/)
phase='contrôle HTTP de la page agents'
page_status=$(curl -q -sS --proto '=https' --max-time 20 -o /dev/null -w '%{http_code}' https://alpes-ex.fr/admin-agents/)
phase='contrôle HTTP de la passerelle MCP'
mcp_status=$(curl -q -sS --proto '=https' --max-time 20 -o /dev/null -w '%{http_code}' https://alpes-ex.fr/api/admin/agents/mcp/)
phase='contrôle HTTP des métadonnées OAuth'
resource_status=$(curl -q -sS --proto '=https' --max-time 20 -o /dev/null -w '%{http_code}' https://alpes-ex.fr/.well-known/oauth-protected-resource)
phase='contrôle du type des métadonnées OAuth'
resource_type=$(curl -q -sS --proto '=https' --max-time 20 -o /dev/null -w '%{content_type}' https://alpes-ex.fr/.well-known/oauth-protected-resource)
echo "Contrôles HTTP page/API/MCP/métadonnées : $page_status/$api_status/$mcp_status/$resource_status ($resource_type)"
phase='validation du statut de l’API agents'
[ "$api_status" = 401 ]
phase='validation du statut de la page agents'
[ "$page_status" = 200 ]
phase='validation du statut de la passerelle MCP'
[ "$mcp_status" = 401 ]
phase='validation du statut des métadonnées OAuth'
[ "$resource_status" = 200 ]
phase='validation du type des métadonnées OAuth'
[[ "$resource_type" == application/json* ]]

trap - ERR
echo 'PASSERELLE_PUBLIEE_DESACTIVEE'
echo "Révision : $revision"
echo "Sauvegarde privée : $backup"
echo "HTTP page/API/MCP/métadonnées : $page_status/$api_status/$mcp_status/$resource_status"
echo 'Aucun événement fictif injecté.'
