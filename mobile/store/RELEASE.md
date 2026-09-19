# Publication App Store / Google Play — préparation

Les projets natifs et la fiche française sont préparés ; aucune fiche n'a été
créée dans les consoles et aucune application n'a été soumise.

## À fournir dans les comptes de publication

- Apple Developer / App Store Connect : équipe, droits de création d'application,
  identifiant définitif disponible et profils de signature iOS.
- Google Play Console : compte éditeur, fiche, identifiant définitif disponible,
  clé d'envoi conservée hors du dépôt et accès à la piste de test.
- Compte de démonstration dans une organisation de recette avec projets fictifs,
  accessible aux équipes de revue sans dépendre d'un poste Windows local.

Ne transmettre aucun mot de passe ou clé privée dans une issue ou dans le dépôt.

## Avant la soumission

1. Terminer la recette sur IONOS et appareils physiques, notamment sessions natives,
   documents, impression/PDF, clavier, rotation et reprise après interruption.
2. Finaliser le parcours d'accès professionnel : l'écran actuel demande une clé de
   licence. Sa conformité aux règles du store doit être résolue avant soumission,
   en particulier pour Apple. Ne pas supposer qu'un emballage natif suffit.
3. Remplacer les icônes/splashes de gabarit par les ressources validées de la marque.
4. Publier et relier une politique de confidentialité propre à l'application,
   l'assistance et le parcours de suppression de compte ; les URL nulles dans la
   fiche doivent être remplacées par des pages effectives.
5. Renseigner App Privacy / Data safety à partir des flux vérifiés : compte,
   identifiant d'appareil, projets et fichiers envoyés à ALPES'Ex. Inclure le
   fonctionnement du cache/brouillons et les durées de conservation réelles.
6. Produire des captures de la version finale sur téléphone et tablette ; ne pas
   présenter les captures de tests simulés comme des captures de recette native.
7. Construire/signature iOS Archive puis TestFlight, et Android AAB signé puis
   piste de test Google Play. Suivre les contrôles demandés dans chaque console.
8. Soumettre la même version testée, attendre les revues Apple/Google et vérifier
   les liens publics avant d'annoncer une disponibilité.

Le workflow GitHub fournit actuellement un APK de debug, un AAB non signé,
une app iOS simulateur et une Preview Windows portable. Ces artefacts ne sont
pas des versions publiées et l'app simulateur n'est pas installable sur iPhone.

Sources officielles vérifiées pendant la préparation :
- https://developer.apple.com/app-store/review/guidelines/
- https://developer.apple.com/help/app-store-connect/
- https://support.google.com/googleplay/android-developer/answer/14151465
