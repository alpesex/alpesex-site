#!/usr/bin/env bash
set -Eeuo pipefail

repository=/home/www/repository
public_root=/home/www/public
app_root=/home/www/app
private_env=/home/www/private/.env
admin_email=alpes.ex.asm@gmail.com
branch=feature/agent-cockpit

cd "$repository"
git fetch origin "$branch"
revision=$(git rev-parse FETCH_HEAD)

backup=$(mktemp -d /home/www/backups/agent-cockpit-XXXXXXXX)
mkdir -p "$backup/public" "$backup/app" "$backup/private"

public_files='admin-agents/agents.css admin-agents/agents.js admin-agents/index.html'
api_file='server/public/api/admin/agents/index.php'
migration_file='server/migrations/016_create_agent_cockpit.sql'
test_file='admin-agents/tests/cockpit.smoke.mjs'

for file in $public_files; do
  mkdir -p "$backup/public/$(dirname "$file")"
  if [ -f "$public_root/$file" ]; then
    cp -p "$public_root/$file" "$backup/public/$file"
  fi
done
if [ -d "$public_root/api/admin/agents" ]; then
  mkdir -p "$backup/public/api/admin"
  cp -a "$public_root/api/admin/agents" "$backup/public/api/admin/"
fi
if [ -f "$app_root/migrations/016_create_agent_cockpit.sql" ]; then
  cp -p "$app_root/migrations/016_create_agent_cockpit.sql" "$backup/app/"
fi
cp -p "$private_env" "$backup/private/.env"

stage=$(mktemp -d /home/www/agent-cockpit-stage-XXXXXXXX)
for file in $public_files $api_file $migration_file $test_file; do
  mkdir -p "$stage/$(dirname "$file")"
  git show "$revision:$file" > "$stage/$file"
  test -s "$stage/$file"
done

php -l "$stage/$api_file"
node --check "$stage/admin-agents/agents.js"
node "$stage/$test_file"

rollback() {
  trap - ERR
  for file in $public_files; do
    if [ -f "$backup/public/$file" ]; then
      mkdir -p "$public_root/$(dirname "$file")"
      cp -p "$backup/public/$file" "$public_root/$file"
    else
      rm -f "$public_root/$file"
    fi
  done
  rm -rf "$public_root/api/admin/agents"
  if [ -d "$backup/public/api/admin/agents" ]; then
    mkdir -p "$public_root/api/admin"
    cp -a "$backup/public/api/admin/agents" "$public_root/api/admin/"
  fi
  if [ -f "$backup/app/016_create_agent_cockpit.sql" ]; then
    cp -p "$backup/app/016_create_agent_cockpit.sql" "$app_root/migrations/016_create_agent_cockpit.sql"
  else
    rm -f "$app_root/migrations/016_create_agent_cockpit.sql"
  fi
  cp -p "$backup/private/.env" "$private_env"
  echo "Publication interrompue. Fichiers restaurés depuis $backup"
  exit 1
}
trap rollback ERR

python3 - "$private_env" "$admin_email" <<'PY'
from pathlib import Path
import secrets
import sys

path = Path(sys.argv[1])
admin = sys.argv[2]
lines = path.read_text().splitlines()
values = {}
for line in lines:
    if '=' in line and not line.lstrip().startswith('#'):
        key, value = line.split('=', 1)
        values[key.strip()] = value.strip()
values['ALPESEX_ADMIN_EMAILS'] = admin
if len(values.get('ALPESEX_AGENT_INGEST_TOKEN', '')) < 32:
    values['ALPESEX_AGENT_INGEST_TOKEN'] = secrets.token_hex(32)
managed = {'ALPESEX_ADMIN_EMAILS', 'ALPESEX_AGENT_INGEST_TOKEN'}
kept = [line for line in lines if line.split('=', 1)[0].strip() not in managed]
kept.extend(f'{key}={values[key]}' for key in sorted(managed))
path.write_text('\n'.join(kept) + '\n')
path.chmod(0o600)
PY

for file in $public_files; do
  destination="$public_root/$file"
  mkdir -p "$(dirname "$destination")"
  install -m 0644 "$stage/$file" "$destination"
done
mkdir -p "$public_root/api/admin/agents" "$app_root/migrations"
install -m 0644 "$stage/$api_file" "$public_root/api/admin/agents/index.php"
install -m 0644 "$stage/$migration_file" "$app_root/migrations/016_create_agent_cockpit.sql"

php "$app_root/bin/migrate.php"

api_status=$(curl -sS --max-time 20 -o "$stage/api.json" -w '%{http_code}' https://alpes-ex.fr/api/admin/agents/)
[ "$api_status" = 401 ]
page_status=$(curl -sS --max-time 20 -o "$stage/page.html" -w '%{http_code}' https://alpes-ex.fr/admin-agents/)
[ "$page_status" = 200 ]
grep -q 'Cockpit des agents' "$stage/page.html"
grep -q 'Session absente ou expirée' "$stage/api.json"

trap - ERR
echo "COCKPIT_PUBLIE"
echo "Révision : $revision"
echo "Administrateur : $admin_email"
echo "Sauvegarde : $backup"
echo "API sans session : HTTP $api_status"
echo "Page cockpit : HTTP $page_status"
echo "Le jeton d'alimentation est conservé uniquement dans $private_env"
