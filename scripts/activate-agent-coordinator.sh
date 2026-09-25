#!/usr/bin/env bash
# Run only after deploy-agent-coordinator.sh reported PASSERELLE_PUBLIEE_DESACTIVEE.
# Prints no value from the private environment file.
set -Eeuo pipefail
umask 077

app_root=/home/www/app
private_env=/home/www/private/.env
redirect=https://chatgpt.com/connector_platform_oauth_redirect

if [ ! -f "$private_env" ] || [ ! -f "$app_root/src/AgentCockpit/OAuth.php" ]; then
  echo 'Passerelle publiée ou configuration privée absente.' >&2
  exit 1
fi
if ! ALPESEX_APP_DIR="$app_root" php -r '$services=require getenv("ALPESEX_APP_DIR")."/bootstrap.php"; if (AlpesEx\Portal\AgentCockpit\OAuth::enabled()) exit(1);'; then
  echo 'Passerelle déjà active ou configuration invalide.' >&2
  exit 1
fi

backup=$(mktemp -d /home/www/backups/agent-coordinator-activation-XXXXXXXX)
chmod 700 "$backup"
cp -p "$private_env" "$backup/.env"
chmod 600 "$backup/.env"

rollback() {
  trap - ERR
  cp -p "$backup/.env" "$private_env"
  echo "Activation interrompue. Configuration restaurée depuis $backup" >&2
  exit 1
}
trap rollback ERR

python3 - "$private_env" "$redirect" <<'PY'
import os
from pathlib import Path
import re
import sys
import tempfile

path = Path(sys.argv[1])
redirect = sys.argv[2]
managed = re.compile(r'^\s*(?:export\s+)?(?:ALPESEX_MCP_(?:ENABLED|REDIRECT_URIS)|ALPESEX_COORDINATOR_WAKE_ENABLED)\s*=')
lines = [line for line in path.read_text().splitlines() if not managed.match(line)]
lines.extend((
    f'ALPESEX_MCP_REDIRECT_URIS={redirect}',
    'ALPESEX_MCP_ENABLED=1',
    'ALPESEX_COORDINATOR_WAKE_ENABLED=1',
))
fd, temporary = tempfile.mkstemp(prefix='.env-agent-', dir=path.parent)
try:
    os.fchmod(fd, 0o600)
    with os.fdopen(fd, 'w') as output:
        output.write('\n'.join(lines) + '\n')
    os.replace(temporary, path)
finally:
    if os.path.exists(temporary):
        os.unlink(temporary)
PY

ALPESEX_APP_DIR="$app_root" php -r '$services=require getenv("ALPESEX_APP_DIR")."/bootstrap.php"; if (!AlpesEx\Portal\AgentCockpit\OAuth::enabled() || !AlpesEx\Portal\AgentCockpit\OAuth::allowedRedirect("https://chatgpt.com/connector_platform_oauth_redirect") || (($_ENV["ALPESEX_COORDINATOR_WAKE_ENABLED"] ?? "") !== "1")) exit(1);'

mcp_status=$(curl -q -sS --proto '=https' --max-time 20 -o /dev/null -w '%{http_code}' https://alpes-ex.fr/api/admin/agents/mcp/)
oauth_status=$(curl -q -sS --proto '=https' --max-time 20 -o /dev/null -w '%{http_code}' 'https://alpes-ex.fr/api/admin/agents/oauth/?flow=token')
metadata_status=$(curl -q -sS --proto '=https' --max-time 20 -o /dev/null -w '%{http_code}' https://alpes-ex.fr/.well-known/oauth-authorization-server)
echo "Contrôles anonymes MCP/OAuth/métadonnées : $mcp_status/$oauth_status/$metadata_status"
[ "$mcp_status" = 401 ]
[ "$oauth_status" = 400 ]
[ "$metadata_status" = 200 ]

trap - ERR
echo 'PASSERELLE_ACTIVEE_SANS_CONNEXION'
echo "Sauvegarde privée de la configuration : $backup"
echo 'Aucun jeton OAuth ni événement fictif créé.'
