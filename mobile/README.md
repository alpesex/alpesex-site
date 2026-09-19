# CPMP ASM pour iPhone, iPad et Android — développement

Base : PR #11, interface web V5.4.18. Identifiant technique provisoire commun :
`fr.alpesex.cpmpasm`. Les versions natives embarquent l'interface et utilisent
l'API HTTPS IONOS par CapacitorHttp. Aucun `server.url` distant, aucune autorisation
HTTP en clair et aucun secret de signature dans le dépôt.

## Construire

Node 22 ou ultérieur :

```sh
cd mobile
npm ci
npm test
npm run sync
```

Android : JDK 21, SDK Android 36 puis `cd android && ./gradlew assembleDebug`.
Pour Play Console : `./gradlew bundleRelease`, puis signature avec la clé de
publication ALPES'Ex. Ne pas distribuer un bundle non signé.

iOS/iPadOS : macOS, Xcode 26+, `npm run ios`, équipe Apple Developer, sélection de
la signature et Archive dans Xcode. Projet universel iPhone/iPad, iOS 15 minimum.
Le manifeste de confidentialité déclare l'usage des dates des fichiers du cache.
Le partage utilise un fichier temporaire privé, supprimé après fermeture du partage.

## Fonctionnement

- L'interface web est copiée depuis `../application` à chaque build.
- Les API sont exclusivement sur `https://alpes-ex.fr/api/` en natif.
- Authentification par session serveur ; les cookies sont gérés par la couche HTTP native.
- Ouverture des documents et exports JSON via la feuille de partage du système.
- Pas de service worker dans l'application native.
- Une liste consultée en arrière-plan ne remplace pas la révision d'un éditeur ouvert.
- Les données locales sont effacées à la déconnexion/changement de compte.
- Une publication écrit le projet central une seule fois. L'ancienne fonction
  « sauvegardes » représente des instantanés courants, pas un historique de versions.

## État exact et limites

Consulter `docs/VALIDATION.md`. Cette branche est une préparation native en
brouillon, pas une application acceptée par Apple ou Google. Les compilations Android et iOS simulateur ont réussi dans GitHub, ainsi que
les tests API sur MariaDB. La recette sur appareil réel reste à réaliser.
La Preview Windows isolée est dans `../desktop`.

Le raccordement Windows au même protocole doit précéder toute mise en production.
Ne pas remplacer la V5.4.18 stable avec cette branche.
