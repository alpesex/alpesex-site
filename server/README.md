# Backend du portail commercial ALPES'Ex

Ce dossier contient le code serveur du portail commercial. Il est distinct de
l'application ERP et de l'installateur IT, qui restent dans
`alpesex/-erp-asm-pro`.

## Déploiement IONOS

Le contenu de ce dossier doit être déployé dans `/home/www/app`.
Les dépendances sont installées avec Composer dans ce même dossier.

Les secrets ne sont jamais versionnés. Le fichier réel se trouve uniquement
dans `/home/www/private/.env`. Le modèle `.env.example` ne contient aucun
secret et documente seulement les variables obligatoires.

Le site accessible publiquement reste dans `/home/www/public`.

## Vérification sans envoi

Depuis une session SSH IONOS :

```bash
cd /home/www/app
/home/www/bin/composer install --no-dev --classmap-authoritative
php bin/check-mail-config.php
```

La commande vérifie la présence et le format des paramètres sans afficher les
mots de passe et sans envoyer de message.

## Principes

- SMTP IONOS chiffré ;
- expéditeur `notifications@alpes-ex.fr` ;
- réponses vers `contact@alpes-ex.fr` ;
- secrets hors de la racine Web et hors de GitHub ;
- erreurs publiques génériques, détails techniques réservés aux journaux ;
- aucun endpoint public de test d'envoi.
# Application mobile et tablette

L’application complète est publiée sous `/application/`. Elle nécessite la migration
`012_create_mobile_application.sql` et une clé de chiffrement de 32 octets dans le fichier
privé `/home/www/private/.env` :

```dotenv
ALPESEX_APPLICATION_KEY=<64 caractères hexadécimaux>
```

La clé peut être générée sur le serveur avec `openssl rand -hex 32`. Elle ne doit jamais être
ajoutée au dépôt. Sa perte rendrait les projets et documents centraux illisibles ; elle doit donc
être sauvegardée dans les mêmes conditions que l’autorité de licences.
