# Installation web de CPMP – ASM

Adresse canonique : `https://alpes-ex.fr/application/`, accessible uniquement
depuis une session authentifiée de l'espace client et depuis un téléphone ou une tablette.

Cette version est une Progressive Web App (PWA). Elle s'installe depuis Internet
sans App Store ni Google Play et ouvre l'interface en mode application. Le bouton
« Installer l'application » affiche le dialogue natif du navigateur quand il est
disponible, ou les étapes adaptées à l'appareil.

## Parcours utilisateur

- iPhone / iPad : ouvrir l'adresse dans Safari, toucher **Partager**, puis
  **Sur l'écran d'accueil** et **Ajouter**.
- Android : ouvrir l'adresse dans Chrome, toucher **Installer l'application**.
  Si le dialogue automatique est indisponible, utiliser le menu Chrome puis
  **Ajouter à l'écran d'accueil**.
- Windows : télécharger l'installateur `.exe` depuis l'espace client. L'application
  Internet refuse volontairement les navigateurs de bureau.

L'icône installée pointe toujours vers `/application/`. La connexion initiale et
la synchronisation nécessitent Internet. Le shell visuel peut se rouvrir après
une coupure, mais l'application ne présente pas cela comme un mode métier hors
ligne complet.

## Conditions d'hébergement

- HTTPS obligatoire sur l'URL publique.
- `manifest.webmanifest` servi en `application/manifest+json`.
- `service-worker.js` servi en JavaScript et sans redirection vers une page de connexion.
- `/application/` et `/application/index.html` protégés par la session client ;
  les API restent protégées par la session et l'activation de licence.
- Ne jamais étendre la liste du cache aux chemins `/api/`, aux projets ou aux
  documents. Les réponses métier utilisent déjà `Cache-Control: no-store`.
- Conserver le scope du service worker à `/application/`.

Après chaque mise à jour, incrémenter le nom `CACHE`. Le service worker récupère
la page principale par le réseau, avec repli sur le shell uniquement si le réseau
est indisponible. Les anciens caches CPMP sont supprimés à l'activation.

## Recette avant mise à disposition

1. Déployer sur un sous-domaine ou environnement IONOS de recette en HTTPS.
2. Vérifier le manifeste et le service worker dans les outils du navigateur.
3. Installer puis lancer depuis l'icône sur iPhone, iPad, Android et Windows.
4. Vérifier connexion, synchronisation, documents, révocation et mise à jour.
5. Déployer ensuite la même révision sur l'URL publique.

Cette préparation ne déploie pas elle-même le site et ne modifie pas la V5.4.18
Windows installée.
