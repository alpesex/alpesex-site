# État de validation — 19 septembre 2026

## Référence et traçabilité

- Dépôt : `alpesex/alpesex-site`, PR #11, branche `agent/mobile-tablet-complete`.
- Base auditée : `96e9f67888ee3b420c18998279161cc365018174`.
- Installeur utilisateur : `CPMP-ASM-Setup-V5.4.18-x64-FINAL.exe`.
- SHA-256 : `87a5b09a5aca1bd166be043176dd8fa8cdaf51e0bf1f668c1d9f72440b4c4453`.
- L'installeur restaure un socle 5.4.10 puis remplace app.asar par la V5.4.18.
  Le dernier app.asar a été extrait du bloc NSIS de remplacement, sans exécuter l'EXE.
- Les deux modèles embarqués PC ASM (consultation et modification) correspondent
  exactement à ceux de la PR #11. Ce constat ne vaut pas validation des intégrations.
- Le main.js V5.4.18 extrait appelle les services locaux `127.0.0.1:47841` et
  `127.0.0.1:47842`. La PR mobile utilise `/api/application/` sur IONOS.
  Ces deux chemins ne constituent pas encore un stockage commun validé.
- Le dépôt Windows principal expose encore V4.7. Ses branches ont été listées ;
  aucune modification du dépôt Windows ou de l'installeur utilisateur n'a été faite.

## Réalisé dans cette reprise

- Projets Capacitor iOS/iPadOS et Android générés avec interface embarquée.
- API native HTTPS, ouverture documentaire et export JSON par partage natif.
- Versions natives 5.4.18, identifiant provisoire `fr.alpesex.cpmpasm`.
- Manifeste de confidentialité pour l'API de dates de fichiers.
- Bouton retour Android raccordé au portefeuille avec confirmation en édition.
- Étiquettes des boutons conservées lisibles sur téléphone ; prise en compte des encoches.
- Révision obligatoire pour modifier un projet existant et contrôle du nombre de lignes
  modifiées : plus de succès annoncé si un autre client a gagné la course.
- Une lecture de liste ne peut plus changer la révision d'un éditeur déjà ouvert.
- Suppression de la double écriture provoquée par l'ancien adaptateur de sauvegarde.
- Nettoyage du portefeuille local à la déconnexion/changement de compte ; chargement
  du portefeuille autorisé avant ouverture de l'interface.
- Mot de passe de confirmation effectivement vérifié côté serveur, avec limitation.
- Activation sérialisée par licence afin de respecter la limite de trois appareils.
- Manager : pas d'accès aux projets d'autres équipes ; contrôle du titulaire courant
  de la licence sur chaque requête.
- Service worker limité aux ressources publiques CPMP, sans effacer les caches d'autres applications.
- Documents servis en pièce jointe pour ne pas exécuter un HTML utilisateur dans l'origine de l'application.

## Résultats effectivement obtenus localement

| Contrôle | Résultat |
|---|---|
| Tests Node de la passerelle (6 scénarios) | Réussis |
| Compilation JS embarquée avec esbuild | Réussie |
| Génération/synchronisation Capacitor Android et iOS | Réussie |
| Analyse syntaxique PHP modifié et test PHP | Réussie avec php-parser ; pas d'exécution PHP locale |
| Analyse syntaxique JavaScript de l'interface | Réussie |
| Comparaison des modèles PC ASM avec l'EXE validé | Identiques |
| Parcours navigateur mobile/tablette | Bloqué par le lancement Chromium dans cet environnement |
| Compilation Gradle / Xcode | Non exécutée localement ; workflow GitHub ajouté |
| Recette IONOS et appareils réels | Non réalisée |

Le workflow `mobile-validation.yml` ne déploie rien. Il prévoit tests Node/PHP,
APK de debug + AAB non signé et application pour simulateur iOS non signée.
Son résultat doit être contrôlé sur le commit exact avant toute promotion.

## Bloquants fonctionnels avant recette finale

1. Raccorder Windows au stockage central avec migration explicite des projets
   existants, conservation des identifiants/révisions et possibilité de retour arrière.
2. Recetter les sessions et cookies natifs contre un environnement IONOS de test.
3. Ajouter la suppression de projet côté serveur : le bouton actuel supprime
   localement et un rechargement peut faire réapparaître le projet.
4. Terminer la synchronisation automatique : la base conserve le bouton Enregistrer
   et charge les projets à la connexion. Le rafraîchissement en cours de session et
   la conservation des brouillons hors ligne ne sont pas encore finalisés.
5. Tester et sérialiser le remplacement simultané d'un même document, puis vérifier
   que les fichiers chiffrés anciens sont supprimés sans perte du nouveau fichier.
6. Recetter PDF/impression, ouverture et annulation du sélecteur de fichiers,
   document 1 Mo, rotation, clavier, retour Android, révocation, expiration et rôles.
7. Remplacer les icônes de gabarit natives par les ressources ALPES'Ex validées.

## Dépôt sur les stores

- Accès Apple Developer / App Store Connect et Google Play Console nécessaires.
- Confirmer l'identifiant disponible, l'équipe de signature, la clé Android et les profils iOS.
- Remplir les fiches, fournir captures réelles et compte de démonstration pour la revue.
- Vérifier le parcours d'accès aux licences pour chaque store : la saisie d'une clé
  dans la base actuelle ne doit pas être présumée compatible avec les règles Apple
  sur l'activation des fonctionnalités et les services professionnels.
- Préparer politique de confidentialité, assistance et parcours de suppression de compte.
- Compléter les déclarations App Privacy / Data safety à partir des flux réels.
- TestFlight et test Google Play avant soumission publique. Les exigences de test
  Google varient notamment selon le type et la date de création du compte.

Références officielles consultées :
- https://capacitorjs.com/docs/getting-started/environment-setup
- https://capacitorjs.com/docs/apis/http
- https://developer.apple.com/app-store/review/guidelines/
- https://support.google.com/googleplay/android-developer/answer/14151465

Aucune disponibilité App Store/Google Play, synchronisation Windows/mobile complète,
validation sur appareil réel ou acceptation des stores n'est annoncée à ce stade.
