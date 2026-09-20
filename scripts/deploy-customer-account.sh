#!/usr/bin/env bash
set -eu
cd /home/www/repository
revision=$(git rev-parse FETCH_HEAD)
base=d94223e6b896f423aaaaa107803074a5c7e0dab6
git merge-base --is-ancestor "$base" "$revision"
backup="/home/www/backups/espace-client-$(date +%Y%m%d-%H%M%S)"
files='mon-compte/index.html mon-compte/mon-compte.css mon-compte/mon-compte.js mentions-legales.html cgu.html cgv.html application/service-worker.js'
mkdir -p "$backup"
for file in $files; do
  mkdir -p "$backup/nouveau/$(dirname "$file")" "$backup/ancien/$(dirname "$file")"
  git show "$revision:$file" > "$backup/nouveau/$file"
  test -s "$backup/nouveau/$file"
  git show "$base:$file" > "$backup/base-check"
  if ! cmp -s "$backup/base-check" "/home/www/public/$file" && ! cmp -s "$backup/nouveau/$file" "/home/www/public/$file"; then
    echo "Arrêt : $file a changé depuis la version prévue. Aucun fichier publié."
    exit 1
  fi
  cp -p "/home/www/public/$file" "$backup/ancien/$file"
done
# Publish styles and logic before the page which uses them.
for file in mon-compte/mon-compte.css mon-compte/mon-compte.js application/service-worker.js mentions-legales.html cgu.html cgv.html mon-compte/index.html; do
  cp "$backup/nouveau/$file" "/home/www/public/$file"
  cmp "$backup/nouveau/$file" "/home/www/public/$file"
done
echo "Espace client et documents légaux publiés. Sauvegarde : $backup"
echo "Rechargez Mon compte avec Ctrl + F5."
