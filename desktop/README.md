# CPMP ASM Windows — Cloud Preview

Version de recette isolée, construite avec la même interface `application/` que
les applications iOS/Android. Elle appelle la même API HTTPS `/api/application/`.
La V5.4.18 fournie par l'utilisateur n'est ni remplacée ni modifiée.

## Construire et vérifier

```sh
cd desktop
npm ci
npm run smoke
npm run dist
```

Le workflow Windows produit un EXE portable **non signé, réservé aux essais**.
Le test de démarrage vérifie l'interface, la passerelle et l'isolation du moteur
Electron, avec une réponse d'authentification simulée. Il ne valide pas IONOS.

Les fichiers de profil sont stockés dans `cpmp-asm-cloud-preview`, séparément de
`cpmp-asm`. Aucune lecture/écriture des anciens fichiers de licence ou des services
locaux 47841/47842. Aucun mécanisme de mise à jour automatique de l'application stable.

## Reprise contrôlée des projets Windows

1. Depuis la V5.4.18 stable, exporter un portefeuille JSON et conserver l'original.
2. Préparer une organisation et des licences de test sur l'environnement Cloud.
3. Ouvrir Cloud Preview, se connecter, importer la copie JSON via l'import
   multi-projets. Vérifier les noms, Todo, comptes rendus, budgets et DC avant édition.
4. Vérifier sur téléphone/tablette que les projets sont présents. Les nouvelles
   écritures sont automatiques : utiliser uniquement des projets de recette.
5. Les métadonnées documentaires sont dans le JSON ; **les fichiers eux-mêmes ne
   sont pas migrés par l'import**. Les transférer explicitement après inventaire,
   vérifier leurs empreintes et respecter la limite de 1 Mo par document.
6. Comparer les résultats sur les trois plateformes avant toute migration réelle.

Le retour à la V5.4.18 se fait en fermant la Preview ; les anciennes données n'ont
pas été modifiées. Les modifications de la Preview ne sont pas répliquées vers
les anciens services locaux. Un export JSON permet de les conserver séparément.

## Contrôles encore requis

Session IONOS réelle, licence/révocation, fichiers et impression/PDF, migration
complète et recette Windows/mobile/tablette sur appareils réels. Le nom et
l'identifiant Preview sont distincts de ceux d'une future mise à jour de production.

Référence technique : https://www.electronjs.org/docs/latest/api/session#sesfetchinput-init
