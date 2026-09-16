# ALPES'Ex Website V6

Site vitrine statique prêt pour GitHub Pages.

## Publication sur GitHub Pages

1. Créez un nouveau dépôt GitHub.
2. Ajoutez tous les fichiers de ce dossier à la racine du dépôt.
3. Ouvrez **Settings → Pages**.
4. Dans **Build and deployment**, sélectionnez **Deploy from a branch**.
5. Choisissez la branche `main` et le dossier `/root`, puis enregistrez.

## Avant la mise en ligne

- Dans `script.js`, remplacez `contact@alpes-ex.fr` par l'adresse e-mail réelle.
- Complétez `mentions-legales.html`.
- Vérifiez que vous disposez des droits nécessaires pour le logo.
- Les photographies utilisent des URLs Unsplash distinctes. Pour une indépendance totale, téléchargez vos propres images libres de droits dans `assets/` puis remplacez les URLs dans `styles.css`.

## Fichiers

- `index.html` : contenu de la page
- `styles.css` : design responsive
- `script.js` : menu, animations, compteurs et formulaire
- `mentions-legales.html` : base à compléter
- `assets/logo-alpesex.png` : logo optimisé avec fond transparent

## Contrôle central des licences

Le portail tient le registre central des licences Master et des licences Utilisateur, Manager et Direction.

- `POST /api/licenses/status/` contrôle la signature, l'enregistrement, l'organisation, la Master parente, la suspension et l'expiration.
- L'espace gestionnaire permet d'importer manuellement une clé signée par l'éditeur officiel.
- Une licence membre ne peut être ajoutée qu'après sa Master et pour une adresse déjà active dans l'organisation.
- La migration `server/migrations/008_create_central_license_registry.sql` reprend les licences déjà émises.

Ordre de déploiement : publier le site et appliquer la migration 008, vérifier que la Master apparaît dans le stock du gestionnaire, puis mettre à jour ASM IT et CPMP-ASM.
