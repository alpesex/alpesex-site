# Cockpit privé des neuf agents ALPES’Ex

La page `/admin-agents/` est réservée à une session active dont l’adresse figure
dans `ALPESEX_ADMIN_EMAILS`. Le navigateur lit `/api/admin/agents/` et Lucas y
répond aux décisions avec un jeton CSRF. L’absence de réponse ne constitue
jamais une validation.

## Connexion du Coordinateur

La passerelle MCP à la demande est `/api/admin/agents/mcp/`. Elle propose
`dossier_upsert`, `enregistrer_evenement`, `demander_decision`,
`lire_decisions`, `lire_entrees` et `traiter_entree`. Elle écrit directement
dans les tables du cockpit ; aucun
processus IA permanent n’est installé. OpenAI Developers n’expose pas d’appel
HTTP générique vers cette API.

Seul le Coordinateur doit disposer de cette connexion. Les huit agents métier
renvoient leurs résultats au Coordinateur dans ChatGPT/Codex. La surface de
délégation doit exclure les outils MCP ; ce contrôle doit être vérifié dans la
session où les neuf compétences personnelles originales sont disponibles. Une
instruction de compétence seule n’est pas une frontière d’autorisation.

La passerelle est désactivée tant que `ALPESEX_MCP_ENABLED` n’est pas `1`. Le
jeton historique `ALPESEX_AGENT_INGEST_TOKEN` reste exclusivement dans
`/home/www/private/.env`. Il n’est jamais envoyé à la connexion MCP ni aux
agents métier. Les tokens OAuth sont aléatoires, stockés seulement sous forme
d’empreinte SHA-256 et expirent après une heure. L’autorisation est liée à la
session administrateur et à une adresse de retour autorisée. Les décisions
sont accessibles en lecture à la passerelle ; seule la session de Lucas peut
les résoudre.

Découverte OAuth : `/.well-known/oauth-protected-resource` et
`/.well-known/oauth-authorization-server`. La connexion privée utilise OAuth
2.1, code d’autorisation et PKCE S256. Le client autorisé pour cette première
version est `https://chatgpt.com/oauth/client.json`. L’adresse de retour exacte
affichée lors de la création de l’application doit être inscrite dans
`ALPESEX_MCP_REDIRECT_URIS` avant l’activation. Si le cookie ALPES’Ex
`SameSite=Strict` ne passe pas le premier saut depuis ChatGPT, la page
d’autorisation permet de reprendre après connexion sur le même domaine.

Référence : https://developers.openai.com/plugins/build/auth

## Contrat des événements

Les agents sont `coordination`, `satisfaction`, `commercial`, `developpement`,
`tests`, `audiovisuel`, `montage`, `web`, `marketing`.

`enregistrer_evenement` reçoit `eventId`, `dossierId`, `agent`, `type`,
`statut`, `resume`, `destinataire` si nécessaire, `livrables`, `decisionLiee`
si nécessaire et `prochaineAction`. Les types acceptés sont
`prise_en_charge`, `progression`, `transmission`, `blocage`,
`decision_requise`, `validation`, `cloture`. Le statut est `active`,
`available` ou `blocked`. L’activité affichée vient de ces statuts pour les
dossiers non clos. Une reprise du même `eventId` et du même contenu retourne
`duplicate: true`. Réutiliser cet identifiant avec un autre contenu est refusé.

Une transmission entre deux métiers exige deux événements : métier →
Coordinateur, puis Coordinateur → destinataire, avec des `eventId` distincts.
`demander_decision` enregistre le Coordinateur comme demandeur, l’origine dans
le contexte, deux ou trois options et leurs impacts, une recommandation et le
travail bloqué. `lire_decisions` retourne l’état réel du dossier. `pending`
n’autorise aucune suite dépendant de la réponse de Lucas.

## File d’entrée et routines

Les formulaires internes ou les intégrations serveur déposent une demande dans
`agent_inputs`. Une référence externe peut être fournie pour dédupliquer une
source telle qu’un formulaire, un courriel ou un événement GitHub. Le jeton
d’ingestion historique peut créer une entrée avec l’action `input_create`, mais
il reste exclusivement côté serveur.

Le Coordinateur appelle `lire_entrees`, puis réserve chaque entrée avec
`traiter_entree` et l’action `prendre` avant de commencer. Chaque cycle fournit
un `executionId` stable : une autre exécution ne peut pas reprendre la même
entrée simultanément. Une entrée passe de
`pending` à `processing`, puis à `completed` ou `rejected`. L’action
`remettre_en_attente` est réservée à un échec réversible. Terminer une entrée
peut l’associer au dossier créé. Les répétitions identiques sont idempotentes ;
une transition incohérente est refusée.

Une tâche planifiée ChatGPT/Codex peut exécuter ce cycle sans API OpenAI
payante : lire la file, réserver les entrées, ouvrir les dossiers, mobiliser
les compétences et consigner les événements. Elle doit conserver tous les
points d’arrêt humains et ne jamais traiter une décision `pending` comme un
accord.

Lorsque `ALPESEX_COORDINATOR_WAKE_ENABLED=1`, chaque nouvelle entrée non
dupliquée et chaque décision résolue envoient un signal technique à la première
adresse valide de `ALPESEX_COORDINATOR_WAKE_EMAIL` ou, à défaut, de
`ALPESEX_ADMIN_EMAILS`. L’objet est exactement
`[ALPES'Ex][COORDINATEUR] RELANCE`. Le message ne contient aucune donnée client,
instruction ou autorisation : la tâche événementielle Gmail doit toujours relire
la file authentifiée du Cockpit. Une panne SMTP est journalisée mais ne remet
jamais en cause l’écriture déjà validée dans le Cockpit.

Le bouton **Relancer le Coordinateur** de la barre supérieure permet à
l’administrateur authentifié d’envoyer manuellement le même signal technique.
L’appel exige le jeton CSRF de la session, est limité à trois relances par tranche
de cinq minutes et ne crée ni décision, ni autorisation, ni instruction métier.

Le POST historique avec le jeton serveur continue d’exister pour préserver
les intégrations existantes. Il refuse désormais les transmissions directes
entre métiers et les demandes de décision sans Coordinateur. Il ne doit pas
être exposé aux agents métier.

## Publication et retour arrière

Après passage du workflow `Agent cockpit validation`, Lucas peut exécuter
`scripts/deploy-agent-coordinator.sh` depuis sa session SSH habituelle avec
le SHA complet contrôlé. Le script refuse une révision sans ancêtre connu ou
des fichiers de production inattendus. Avant toute copie dans le public ou
l’application, il sauvegarde les anciens fichiers concernés et un dump
cohérent complet de la base dans `/home/www/backups/agent-coordinator-*`.
Le répertoire est en 0700 et les données en 0600. Il s’arrête si le dump
échoue ; `mariadb-dump` ou `mysqldump` doit être disponible. Aucun secret n’est
affiché. La passerelle reste désactivée après la publication.

Le script vérifie la syntaxe PHP/JS, applique la migration 017, reconstruit
l'autoload Composer optimisé après sauvegarde de sa version précédente, puis contrôle
page 200, API 401, MCP 401 et métadonnées OAuth 200/JSON. Ensuite,
`scripts/activate-agent-coordinator.sh` ajoute l'URI de retour stable de ChatGPT
et `ALPESEX_MCP_ENABLED=1` au fichier privé, après sauvegarde et avec restauration
automatique si les contrôles anonymes échouent. Il n'affiche aucune valeur privée.
Vérifier que la page de gestion de la connexion ChatGPT présente bien l'URI
`https://chatgpt.com/connector_platform_oauth_redirect` et le document client
`https://chatgpt.com/oauth/client.json`. Vérifier ensuite la connexion dans
ChatGPT/Codex, les refus sans session et pour un autre compte, la liste des
six outils, l’absence de secret dans les réponses et l’exclusion des huit
agents métier.

Si une étape du script échoue, les fichiers sont restaurés. La migration ajoute
une colonne nullable et des tables isolées ; elle ne supprime aucune donnée
existante. Après activation, remettre `ALPESEX_MCP_ENABLED=0`, exécuter
`scripts/rollback-agent-coordinator.sh` avec le répertoire de sauvegarde
imprimé par le déploiement. Ce script révoque les accès OAuth et restaure
uniquement les fichiers concernés. Il ne touche pas aux données client.
Le dump complet ne doit pas être restauré automatiquement : cela écraserait
des écritures clients postérieures. Comparer les écritures avant toute
restauration ciblée de données.

Pour publier ensuite la file d’entrée, exécuter d'abord
`scripts/disable-agent-coordinator.sh` : il sauvegarde le fichier privé, remplace
uniquement les variables d'activation de la passerelle et du réveil, et n'affiche
aucune valeur sensible. Utiliser
ensuite `scripts/deploy-agent-automation.sh` avec le SHA complet de la branche
contrôlée. Le script sauvegarde les fichiers et la base, applique la migration 018
et laisse la passerelle désactivée. Après les contrôles serveur, la réactiver avec
`scripts/activate-agent-coordinator.sh`, qui réactive également le réveil Gmail.
Le retour arrière ciblé utilise
`scripts/rollback-agent-automation.sh` et le répertoire de sauvegarde imprimé ;
la table additive `agent_inputs` reste conservée pour éviter toute perte de
demande reçue.

## Recette fictive

Ne lancer `TEST-COCKPIT-9-AGENTS` que lorsque les neuf compétences originales
sont accessibles dans la session et que la connexion MCP est vérifiée. Suivre
les quinze étapes de la demande initiale, sans devis, déploiement, envoi ou
campagne réelle. Ne pas inventer une réponse de Lucas. Rejouer un événement
pour vérifier la déduplication. Après validation, sauvegarder la base avant
d'exécuter l'archivage. L'outil de sauvegarde reste dans le dépôt Git et n'est
pas installé dans `/home/www/app/bin/` :

```bash
cd /home/www/repository &&
backup=$(mktemp -d /home/www/backups/agent-test-archive-XXXXXXXX) &&
chmod 700 "$backup" &&
git show 8e8cdc09de72dae5abc34b87f293c45cba48e5b2:server/bin/backup-agent-database.php > "$backup/backup-agent-database.php" &&
test -s "$backup/backup-agent-database.php" &&
ALPESEX_APP_DIR=/home/www/app php "$backup/backup-agent-database.php" "$backup" &&
test -s "$backup/database.sql" &&
php /home/www/app/bin/archive-agent-test.php TEST-COCKPIT-9-AGENTS
```

L'archivage clôt le dossier, annule ses décisions encore en attente et conserve
le journal.

Les tests CI utilisent une base jetable et un dossier fictif local. Ils ne
contactent pas la production.
