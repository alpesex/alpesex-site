#!/usr/bin/env bash
# Run only after setting ALPESEX_MCP_ENABLED=0 in /home/www/private/.env.
# Requires the exact backup directory printed by deploy-agent-coordinator.sh.
set -Eeuo pipefail
umask 077

backup=${1:-}
case "$backup" in
  /home/www/backups/agent-coordinator-*) ;;
  *) echo 'Répertoire de sauvegarde agent-coordinator requis.' >&2; exit 1 ;;
esac
backup=$(realpath -e -- "$backup")
case "$backup" in
  /home/www/backups/agent-coordinator-*) ;;
  *) echo 'Sauvegarde hors du répertoire autorisé.' >&2; exit 1 ;;
esac
if [ ! -s "$backup/database.sql" ] || [ ! -d "$backup/previous" ]; then
  echo 'Sauvegarde incomplète.' >&2
  exit 1
fi

app_root=/home/www/app
public_root=/home/www/public
if ALPESEX_APP_DIR="$app_root" php -r '$services=require getenv("ALPESEX_APP_DIR")."/bootstrap.php"; if (($_ENV["ALPESEX_MCP_ENABLED"] ?? "") === "1") exit(1);'; then
  :
else
  echo 'Désactiver ALPESEX_MCP_ENABLED avant le retour arrière.' >&2
  exit 1
fi

php "$app_root/bin/revoke-agent-tokens.php" revoke-all

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
    server/src/*|server/bin/*|server/migrations/*) printf '%s/%s' "$app_root" "${1#server/}" ;;
    *) printf '%s/%s' "$public_root" "$1" ;;
  esac
}

for file in "${files[@]}"; do
  destination=$(target "$file")
  if [ -f "$backup/previous/$file" ]; then
    mkdir -p "$(dirname "$destination")"
    cp -p -- "$backup/previous/$file" "$destination"
    cmp -s -- "$backup/previous/$file" "$destination"
  else
    rm -f -- "$destination"
  fi
done

echo 'ANCIENS_FICHIERS_RESTAURES'
echo 'Les tables et colonnes ajoutées restent isolées. Le dump DB est conservé.'
echo 'Vérifier page 200 et API non authentifiée 401.'
