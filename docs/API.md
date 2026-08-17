# API Yzendra ID

Service d'identité central pour le portefeuille Yzendra. Un seul compte
(email + mot de passe) utilisable par plusieurs apps (vitrine, Equi,
futurs outils) sur l'infra mutualisée. Ce service ne fait QUE
l'authentification — pas de facturation, pas de données métier : chaque
app garde ses propres données (abonnements côté vitrine, profil cavalier
côté Equi, etc.) et se contente de retenir l'identifiant de compte central
(`yzendraAccountId`) pour faire le lien.

Une app (Equi, vitrine) qui veut utiliser ce service :
1. Appelle `POST /api/register` ou `POST /api/login` de CE service pour
   créer/vérifier le compte central.
2. Récupère le token JWT retourné.
3. Vérifie ce token elle-même en important la clé publique de ce service
   (`config/jwt/public.pem`) dans sa propre config `lexik/jwt-authentication-bundle`.

C'est délibérément optionnel par déploiement : une installation "Entreprise"
dédiée à un client peut tourner sans jamais appeler ce service, avec une
auth 100% locale (voir `AUTH_MODE` dans le README d'Equi).

## Base URL

```
http://217.154.121.159:8091/api
```

## `POST /api/register`

| Champ    | Type   | Contrainte                   |
|----------|--------|-------------------------------|
| email    | string | requis, format email valide  |
| prenom   | string | requis, max 255 caractères   |
| nom      | string | requis, max 255 caractères   |
| password | string | requis, 6 à 4096 caractères  |

```bash
curl -X POST http://217.154.121.159:8091/api/register \
  -H "Content-Type: application/json" \
  -d '{"email":"cavalier@example.com","prenom":"Camille","nom":"Cavalier","password":"motdepasse123"}'
```

Réponse `201` : `{"token": "...", "account": {"id", "email", "prenom", "nom", "roles", "dateCreation"}}`

Erreurs : `422` (données invalides, détail par champ), `409` (email déjà utilisé).

## `POST /api/login`

```bash
curl -X POST http://217.154.121.159:8091/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"cavalier@example.com","password":"motdepasse123"}'
```

Réponse `200` : `{"token": "..."}`. Erreur `401` si identifiants invalides.

## `GET /api/me`

```bash
curl http://217.154.121.159:8091/api/me -H "Authorization: Bearer <token>"
```

Réponse `200` : `{"id", "email", "prenom", "nom", "roles", "dateCreation"}`. `401` sans token valide.

## Notes techniques

- Implémentation identique (copiée puis adaptée) à celle construite le
  2026-08-17 pour l'API d'Equi (`lexik/jwt-authentication-bundle`, deux
  firewalls `api_login`/`api`, route vide `/api/login` dans
  `config/routes.yaml` — indispensable, sinon le `RouterListener` de
  Symfony 404 avant même que le firewall JWT s'exécute).
- Token JWT RS256, durée de vie 1h (défaut du bundle), pas de refresh
  token pour l'instant.
- La clé publique (`config/jwt/public.pem`, générée par `infra/setup.sh`)
  doit être copiée manuellement vers toute app qui veut vérifier des
  tokens émis ici (Equi en `AUTH_MODE=central`, par exemple). Pas
  d'endpoint JWKS automatisé pour l'instant — à faire si un jour ce
  service tourne sur un serveur séparé des apps qui le consomment.
- Aucune notion de "profil métier" ici volontairement : `Account` ne
  contient que ce qui sert à authentifier (email, mot de passe, prenom,
  nom). Les données propres à chaque app (abonnements, chevaux...)
  restent dans la base de cette app, liées via `yzendraAccountId`.
