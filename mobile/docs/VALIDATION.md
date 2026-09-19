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
- Suppression de l’appel hérité à `ensurePassword`, inexistant, qui bloquait le démarrage.
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

## Résultats effectivement obtenus

| Contrôle | Résultat |
|---|---|
| Tests Node de la passerelle (6 scénarios) | Réussis |
| Compilation JS embarquée avec esbuild | Réussie |
| Génération/synchronisation Capacitor Android et iOS | Réussie |
| Analyse syntaxique PHP modifié et test PHP | Réussie localement ; tests PHP exécutés avec succès dans GitHub |
| Analyse syntaxique JavaScript de l'interface | Réussie |
| Comparaison des modèles PC ASM avec l'EXE validé | Identiques |
| Parcours navigateur mobile/tablette | Connexion, portefeuille, PC lecture/modification, DC modification et déconnexion réussis en 390/768/1024 px avec API simulée |
| Compilation Gradle / Xcode | Android APK debug + AAB non signé et iOS simulateur réussis sur 9bb1263 (exécution GitHub 35441315468) |
| Recette IONOS et appareils réels | Non réalisée |

Le workflow `mobile-validation.yml` ne déploie rien. Il exécute tests Node/PHP et navigateur,
APK de debug + AAB non signé et application pour simulateur iOS non signée.
Son résultat doit être contrôlé sur le commit exact avant toute promotion.

## Bloquants fonctionnels avant recette finale

1. Recetter la Preview Windows `desktop/`, qui utilise maintenant la même API.
   La migration réelle des projets et fichiers de la V5.4.18 reste à réaliser.
2. Recetter les sessions et cookies natifs contre un environnement IONOS de test.
3. Déployer en recette les migrations 012 et 013, puis vérifier la suppression
   multi-appareils. La suppression serveur et les tombstones sont implémentés et testés.
4. Recetter la synchronisation automatique ajoutée : file persistante de brouillons,
   écritures séquentielles, récupération réseau, rafraîchissement hors éditeur et
   résolution explicite des conflits. La connexion initiale nécessite le réseau.
5. Le remplacement simultané des documents est sérialisé par verrou SQL et testé
   avec MariaDB. Vérifier aussi les permissions du stockage IONOS réel.
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

Builds vérifiés : https://github.com/alpesex/alpesex-site/actions/runs/35441315468

## Suite de la reprise

- Exécution GitHub 35442007882 (commit d6a5b42) : trois jobs réussis, dont test
  HTTP de la vraie API PHP sur MariaDB 11.4 temporaire et compilations Android/iOS.
- Scénarios API : éditions concurrentes (200/409), Direction en lecture seule,
  isolation entre organisations, remplacement concurrent de document, chiffrement
  au repos, taille limite, suppression et absence de résurrection, activations
  concurrentes avec plafond de trois appareils.
- Nouvelle file de synchronisation : les révisions d'origine restent attachées
  aux brouillons ; une panne/conflit ne les efface pas. Un changement de compte
  nettoie les données locales. La déconnexion est bloquée tant que les brouillons
  ne sont pas envoyés ou explicitement abandonnés via le dialogue de conflit.
- Rafraîchissement toutes les 15 secondes en consultation/portefeuille ; sauvegarde
  des changements toutes les 3 secondes quand l'application est visible. Un éditeur
  ouvert n'est jamais remplacé en arrière-plan. Pas de promesse de tâches iOS en fond.
- Preview Windows isolée ajoutée avec compilation et essai de démarrage en CI.
  L'EXE de production n'est pas modifié ; voir `desktop/README.md` pour la reprise.

Ces derniers ajouts doivent encore passer le workflow sur leur commit exact.
