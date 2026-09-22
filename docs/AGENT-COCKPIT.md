# Cockpit des agents ALPES'Ex

## Périmètre

- page privée : `/admin-agents/` ;
- lecture et décisions : `/api/admin/agents/` avec session ALPES'Ex et adresse administrateur autorisée ;
- alimentation automatisée : même API avec un jeton Bearer serveur ;
- stockage : migration `016_create_agent_cockpit.sql`.

La page publique et l'espace client ne sont pas modifiés.

## Contrat d'alimentation

Les noms d'agents acceptés sont `coordination`, `satisfaction`, `commercial`,
`developpement`, `tests`, `audiovisuel`, `montage`, `web` et `marketing`.

Chaque requête est un `POST` JSON vers `/api/admin/agents/` avec :

```http
Authorization: Bearer <ALPESEX_AGENT_INGEST_TOKEN>
Content-Type: application/json
```

### Ouvrir ou mettre à jour un dossier

```json
{
  "action": "dossier_upsert",
  "dossierId": "ALX-2026-001",
  "title": "Évolution CPMP-ASM",
  "client": "Interne",
  "version": "6.0.2",
  "status": "open",
  "priority": "normal",
  "currentAgent": "developpement"
}
```

### Journaliser une transmission

```json
{
  "action": "event",
  "dossierId": "ALX-2026-001",
  "sourceAgent": "developpement",
  "targetAgent": "tests",
  "eventType": "delivery",
  "summary": "Correctif transmis pour recette",
  "payload": {"version": "6.0.2"}
}
```

### Demander une décision à Lucas

```json
{
  "action": "decision_request",
  "decisionId": "DEC-2026-001",
  "dossierId": "ALX-2026-001",
  "requesterAgent": "web",
  "question": "Autoriser le déploiement en production ?",
  "whyNow": "La recette est validée et le retour arrière est prêt.",
  "options": ["Déployer maintenant", "Planifier le déploiement", "Ne pas déployer"],
  "impacts": {"Déployer maintenant": "Interruption estimée : aucune"},
  "recommendation": "Planifier le déploiement",
  "urgency": "normal",
  "blockedWork": "Publication en production"
}
```

Le navigateur ne peut pas créer une demande de décision. Il peut uniquement
répondre à une décision déjà présentée par le Coordinateur, avec un jeton CSRF
lié à la session de Lucas.

## Activation et retour arrière

1. Sauvegarder la base et la version publique actuelle.
2. Déployer les fichiers publics et l'application serveur.
3. Configurer `ALPESEX_ADMIN_EMAILS` et `ALPESEX_AGENT_INGEST_TOKEN` dans le
   fichier privé du serveur.
4. Exécuter `php bin/migrate.php`.
5. Vérifier l'accès refusé sans session, l'accès refusé à un compte non autorisé,
   puis l'accès de Lucas.
6. Injecter un dossier et un événement de contrôle, puis les supprimer si nécessaire.

En cas d'échec, restaurer les fichiers précédents. Les nouvelles tables sont
isolées et ne modifient aucune donnée client ou donnée CPMP-ASM existante.
