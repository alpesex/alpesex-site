#!/usr/bin/env bash
set -eu
cd /home/www/repository
revision=$(git rev-parse FETCH_HEAD)
base=1ddc5cfc661c9a3cf22c2bf288afc29d47558e97
git merge-base --is-ancestor "$base" "$revision"
backup="/home/www/backups/cle-licence-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$backup/nouveau/mon-compte" "$backup/ancien/mon-compte" "$backup/nouveau/api/team/licenses" "$backup/ancien/api/team/licenses"
for file in mon-compte/index.html mon-compte/mon-compte.css mon-compte/mon-compte.js api/team/licenses/index.php; do
  source="$file"
  case "$file" in api/*) source="server/public/$file";; esac
  git show "$revision:$source" > "$backup/nouveau/$file"
  test -s "$backup/nouveau/$file"
  git show "$base:$source" > "$backup/base-check"
  if ! cmp -s "$backup/base-check" "/home/www/public/$file" && ! cmp -s "$backup/nouveau/$file" "/home/www/public/$file"; then
    echo "Arrêt : $file a changé depuis la version prévue. Aucun fichier publié."
    exit 1
  fi
  cp -p "/home/www/public/$file" "$backup/ancien/$file"
done
php -l "$backup/nouveau/api/team/licenses/index.php"
for file in api/team/licenses/index.php mon-compte/mon-compte.css mon-compte/mon-compte.js mon-compte/index.html; do
  cp "$backup/nouveau/$file" "/home/www/public/$file"
  cmp "$backup/nouveau/$file" "/home/www/public/$file"
done
echo "Clé complète : publication terminée. Sauvegarde : $backup"
