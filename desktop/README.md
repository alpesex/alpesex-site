# CPMP ASM Windows — application Cloud

Version Windows de production construite avec la même interface métier que les
applications mobiles. Elle appelle la même API HTTPS `/api/application/` et
utilise IONOS comme stockage central.

## Construire et vérifier

```sh
cd desktop
npm ci
npm run smoke
npm run dist
```

Le workflow Windows produit un installateur NSIS **non signé, réservé aux essais**
tant que le certificat de signature de code n'est pas configuré.
Le test de démarrage vérifie l'interface, la passerelle et l'isolation du moteur
Electron, avec une réponse d'authentification simulée. Il ne valide pas IONOS.

Le profil `cpmp-asm` est repris afin de détecter le portefeuille local V5.4.18.
Au premier rattachement à un compte Cloud, les projets locaux sont placés dans la
file de synchronisation avant le premier téléchargement IONOS.

## Reprise contrôlée des projets Windows

1. Conserver l'installation V5.4.18 et exporter un portefeuille JSON de sécurité.
2. Le premier démarrage crée une copie du profil dans
   `cpmp-asm-v5.4.18-backup` avant toute migration.
3. Se connecter avec le compte et la licence associés : le portefeuille local est
   placé dans la file Cloud avant le premier chargement IONOS.
4. Vérifier sur téléphone/tablette que les projets sont présents. Les nouvelles
   écritures sont automatiques : utiliser uniquement des projets de recette.
5. Les métadonnées documentaires sont dans le JSON ; **les fichiers eux-mêmes ne
   sont pas migrés par l'import**. Les transférer explicitement après inventaire,
   vérifier leurs empreintes et respecter la limite de 1 Mo par document.
6. Comparer les résultats sur les trois plateformes avant toute migration réelle.

Le retour reste possible à partir de la copie du profil et de l'export JSON de
sécurité tant que la recette réelle n'est pas validée.

## Contrôles encore requis

Signature de code, session IONOS réelle, licence/révocation, fichiers et
impression/PDF, migration complète et recette sur appareils réels.

Référence technique : https://www.electronjs.org/docs/latest/api/session#sesfetchinput-init
