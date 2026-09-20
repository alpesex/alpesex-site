#!/usr/bin/env bash
set -Eeuo pipefail
cd /home/www/repository
revision=$(git rev-parse FETCH_HEAD)
base=9008debab04c08e394564fc8a60c49d9379a85a6
git merge-base --is-ancestor "$base" "$revision"
backup="/home/www/backups/gestionnaire-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$backup/staged" "$backup/previous"
files='server/src/Team/ManagerLicenses.php server/src/Licensing/LicenseIssuer.php server/src/Licensing/ManualLicenseRegistrar.php server/src/Auth/RegisterInvitedUser.php server/src/Auth/Login.php server/public/api/team/management/index.php server/public/api/downloads/it/index.php server/public/api/team/licenses/index.php mon-compte/manager.js mon-compte/mon-compte.js mon-compte/mon-compte.css mon-compte/index.html'
target() {
  case "$1" in
    server/src/*) printf '/home/www/app/%s' "${1#server/}";;
    server/public/*) printf '/home/www/public/%s' "${1#server/public/}";;
    *) printf '/home/www/public/%s' "$1";;
  esac
}
# Prepare and validate everything before changing any deployed file.
for file in $files; do
  dest=$(target "$file")
  mkdir -p "$backup/staged/$(dirname "$file")" "$backup/previous/$(dirname "$file")"
  git show "$revision:$file" > "$backup/staged/$file"
  test -s "$backup/staged/$file"
  case "$file" in *.php) php -l "$backup/staged/$file";; esac
  if [ -f "$dest" ]; then
    cp -p "$dest" "$backup/previous/$file"
    if ! cmp -s "$dest" "$backup/staged/$file"; then
      if ! git show "$base:$file" > "$backup/expected" 2>/dev/null || ! cmp -s "$dest" "$backup/expected"; then
        echo "Arrêt : $dest a évolué. Aucun fichier publié."
        exit 1
      fi
    fi
  elif git cat-file -e "$base:$file" 2>/dev/null; then
    echo "Arrêt : fichier attendu absent : $dest. Aucun fichier publié."
    exit 1
  fi
done
# Check the actual configured installer and signing authority without exposing keys.
php <<'PHP'
<?php
require '/home/www/app/bootstrap.php';
$file=$_ENV['ALPESEX_IT_INSTALLER']??getenv('ALPESEX_IT_INSTALLER')?:'/home/www/private/downloads/ASM-IT-Setup-V0.5.11-x64-FINAL.exe';
if(!is_readable($file)||hash_file('sha256',$file)!=='67f4a0bddfac23052f0cf745d25f78eca3adf08736bd7f78d500a347e2d78ae6'){
    fwrite(STDERR,"Installateur IT-ASM V0.5.11 absent ou différent. Déposez le fichier fourni dans /home/www/private/downloads/ avant de relancer. Aucun fichier publié.\n");exit(1);
}
(new AlpesEx\Portal\Licensing\LicenseIssuer())->issueUser(['id'=>'configuration-check','masterId'=>'configuration-master','organizationId'=>'configuration-org','deploymentId'=>'configuration-deployment','email'=>'configuration@example.test']);
echo "Installateur IT-ASM et autorité de signature vérifiés.\n";
PHP
cp -a /home/www/app/vendor/composer "$backup/composer"
rollback() {
  trap - ERR
  for file in $files; do
    dest=$(target "$file")
    if [ -f "$backup/previous/$file" ]; then cp -p "$backup/previous/$file" "$dest"; else rm -f "$dest"; fi
  done
  cp -a "$backup/composer/." /home/www/app/vendor/composer/
  echo "Publication interrompue : ancienne version restaurée. Sauvegarde : $backup"
  exit 1
}
trap rollback ERR
for file in $files; do
  case "$file" in server/src/*)
    dest=$(target "$file");mkdir -p "$(dirname "$dest")";cp "$backup/staged/$file" "$dest";;
  esac
done
(cd /home/www/app && /home/www/bin/composer dump-autoload --no-dev --classmap-authoritative)
for file in $files; do
  case "$file" in server/src/*) continue;; esac
  dest=$(target "$file");mkdir -p "$(dirname "$dest")";cp "$backup/staged/$file" "$dest";cmp "$backup/staged/$file" "$dest"
done
for route in team/management downloads/it; do
  code=$(curl -sS --max-time 20 -o /dev/null -w '%{http_code}' "https://alpes-ex.fr/api/$route/")
  [ "$code" = 401 ] || { echo "Contrôle d’accès inattendu sur $route : $code"; false; }
done
trap - ERR
echo "ESPACE_GESTIONNAIRE_PUBLIE — sauvegarde : $backup"
echo "Rechargez Mon compte avec Ctrl + F5."
