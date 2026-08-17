# Yzendra ID

Service d'identité central du portefeuille Yzendra : un compte (email +
mot de passe) partagé entre les différentes apps mutualisées (vitrine,
Equi, futurs outils), pour éviter à un utilisateur de s'inscrire
séparément sur chacune.

Ce service **ne gère que l'authentification** — aucune donnée métier
(pas d'abonnement, pas de profil applicatif). Chaque app garde ses propres
données locales, liées à un compte central via un identifiant
(`yzendraAccountId`).

Voir [docs/API.md](docs/API.md) pour le détail des routes.

## Pourquoi un service séparé plutôt qu'intégré à une app existante

- Doit pouvoir évoluer indépendamment de vitrine et d'Equi
- Une installation "Entreprise" dédiée à un client (VPS isolé) doit
  pouvoir tourner **sans en dépendre du tout** — l'auth locale de chaque
  app (Equi notamment) reste possible et fonctionnelle par défaut,
  utiliser ce service central est une option activée explicitement
  (`AUTH_MODE=central` côté Equi)

## Stack

- Symfony 7.4 (PHP 8.3-FPM), API pure — pas de Twig/AssetMapper (pas
  d'interface web ici, uniquement des routes JSON)
- PostgreSQL 16
- `lexik/jwt-authentication-bundle` (JWT RS256)
- Nginx (reverse proxy vers PHP-FPM), Docker + Docker Compose

## Portabilité

Même pattern que vitrine/equi : dépôt auto-suffisant (code + infra), nom
de projet Compose figé en dur (`name: yzendraid`), secrets exclus du Git,
`infra/setup.sh` pour bootstrapper un serveur neuf en une commande.

```bash
git clone git@github.com:Tanjiro356045/YzendraID.git
cd YzendraID/infra
bash setup.sh
```

- Port nginx : `8091` (ne rentre pas en collision avec vitrine
  80/443/8080 ni Equi 8090/8081, sur le même VPS)
- Adminer : `127.0.0.1:8082` (tunnel SSH uniquement)
- DB : `yzendraid_db` / user `yzendraid_app`

## Développement au quotidien

```bash
cd infra
docker compose up -d
docker compose exec php bin/console <commande>
```
