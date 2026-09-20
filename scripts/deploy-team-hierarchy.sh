#!/usr/bin/env bash
set -Eeuo pipefail
cd /home/www/repository
revision=$(git rev-parse FETCH_HEAD)
base=8317495dd3db47b731ecd35eaf3b5a3639f5dd61
git merge-base --is-ancestor "$base" "$revision"
backup=$(mktemp -d /home/www/backups/organigramme-XXXXXXXX)
files='server/src/Team/TeamHierarchy.php server/src/Application/ApplicationAccess.php server/public/api/team/hierarchy/index.php mon-compte/hierarchy.js mon-compte/manager.js mon-compte/mon-compte.css mon-compte/index.html'
target(){
 case "$1" in
 server/src/*) printf '/home/www/app/%s' "${1#server/}";;
 server/public/*) printf '/home/www/public/%s' "${1#server/public/}";;
 *) printf '/home/www/public/%s' "$1";;
 esac
}
git show "$revision:scripts/patch-team-access.php" > "$backup/patch.php"
git show "$revision:server/migrations/014_create_team_hierarchy.sql" > "$backup/migration.sql"
for file in $files; do
 dest=$(target "$file")
 mkdir -p "$backup/staged/$(dirname "$file")" "$backup/previous/$(dirname "$file")"
 if [ "$file" = server/src/Application/ApplicationAccess.php ]; then
   php "$backup/patch.php" "$dest" "$backup/staged/$file"
 else
   git show "$revision:$file" > "$backup/staged/$file"
   if [ -f "$dest" ]; then
     if ! cmp -s "$dest" "$backup/staged/$file"; then
       if ! git show "$base:$file" > "$backup/expected" 2>/dev/null || ! cmp -s "$dest" "$backup/expected"; then
         echo "Arrêt : $dest a évolué. Aucun fichier publié."; exit 1
       fi
     fi
   elif git cat-file -e "$base:$file" 2>/dev/null; then
     echo "Arrêt : fichier attendu absent : $dest"; exit 1
   fi
 fi
 test -s "$backup/staged/$file"
 if [ -f "$dest" ]; then cp -p "$dest" "$backup/previous/$file"; fi
 case "$file" in *.php) php -l "$backup/staged/$file";; esac
done
# Additive migration only: no existing team or project data is changed.
php -r '$services=require "/home/www/app/bootstrap.php"; $pdo=AlpesEx\Portal\Database::connect($services["config"]); $pdo->exec(file_get_contents($argv[1])); echo "Tables organigramme prêtes.\n";' "$backup/migration.sql"
rollback(){
 trap - ERR
 for file in $files; do
  dest=$(target "$file")
  if [ -f "$backup/previous/$file" ]; then cp -p "$backup/previous/$file" "$dest"; else rm -f "$dest"; fi
 done
 echo "Publication interrompue, fichiers restaurés : $backup"; exit 1
}
trap rollback ERR
for file in $files; do
 dest=$(target "$file");mkdir -p "$(dirname "$dest")"
 temp=$(mktemp "$(dirname "$dest")/.organigramme-XXXXXXXX")
 cp "$backup/staged/$file" "$temp";chmod 644 "$temp";mv "$temp" "$dest"
done
code=$(curl -sS --max-time 20 -o /dev/null -w '%{http_code}' https://alpes-ex.fr/api/team/hierarchy/)
[ "$code" = 401 ] || { echo "Contrôle d’accès inattendu : $code"; false; }
trap - ERR
echo "ORGANIGRAMME_PUBLIE — sauvegarde : $backup"
echo "Rechargez Mon compte avec Ctrl + F5, puis configurez et enregistrez les équipes."
