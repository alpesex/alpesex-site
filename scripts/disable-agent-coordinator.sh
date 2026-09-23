#!/usr/bin/env bash
# Temporarily disables the MCP gateway without printing private configuration.
set -Eeuo pipefail
umask 077

app_root=/home/www/app
private_env=/home/www/private/.env

if [ ! -f "$private_env" ] || [ ! -f "$app_root/bootstrap.php" ]; then
  echo 'Application ou configuration privée absente.' >&2
  exit 1
fi

backup=$(mktemp -d /home/www/backups/agent-coordinator-disable-XXXXXXXX)
chmod 700 "$backup"
cp -p "$private_env" "$backup/.env"
chmod 600 "$backup/.env"

rollback() {
  trap - ERR
  cp -p "$backup/.env" "$private_env"
  echo "Désactivation interrompue. Configuration restaurée depuis $backup" >&2
  exit 1
}
trap rollback ERR

python3 - "$private_env" <<'PY'
import os
from pathlib import Path
import re
import sys
import tempfile

path = Path(sys.argv[1])
managed = re.compile(r'^\s*(?:export\s+)?ALPESEX_MCP_ENABLED\s*=')
lines = [line for line in path.read_text().splitlines() if not managed.match(line)]
lines.append('ALPESEX_MCP_ENABLED=0')
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

ALPESEX_APP_DIR="$app_root" php -r '$services=require getenv("ALPESEX_APP_DIR")."/bootstrap.php"; if (($_ENV["ALPESEX_MCP_ENABLED"] ?? "") === "1") exit(1);'

trap - ERR
echo 'PASSERELLE_DESACTIVEE'
echo "Sauvegarde privée de la configuration : $backup"
echo 'Aucun jeton, secret ou événement affiché.'
