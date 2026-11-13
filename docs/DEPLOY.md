# Déploiement (VM + GHCR privé)

Les images sont buildées sur le poste, poussées sur GitHub Container Registry (privé), puis tirées par la VM.
La VM n'a besoin que de `compose.prod.yaml` et `stack.env` : pas de code source.

## Une seule fois

### 1. Tokens GitHub
GitHub → Settings → Developer settings → Personal access tokens (classic) :
- **poste** : scopes `write:packages` + `read:packages`
- **VM** : scope `read:packages` uniquement

### 2. Poste
```bash
podman login ghcr.io -u baptistemeunier     # coller le token "poste"
cp stack.env.example stack.env           # remplir les secrets
```
Puis release (voir « Tags d'images »). Les packages GHCR sont **privés par défaut** (vérifier dans GitHub → Packages).

### 3. VM (Docker + compose v2)
```bash
ssh vm 'mkdir -p ~/daily-budget'
scp compose.prod.yaml stack.env vm:~/daily-budget/
ssh vm
cd ~/daily-budget
docker login ghcr.io -u baptistemeunier     # coller le token "VM"
docker compose -f compose.prod.yaml --env-file stack.env up -d
```
Seul le front est publié (`FRONT_PORT`, 3000 par défaut). Mettre un reverse proxy TLS devant.

## Tags d'images
- `bin/release.sh v1.2.0` : pousse `v1.2.0` + `latest`. Refuse s'il y a des modifs non commitées.
- `bin/release.sh dev` : pousse l'état courant sous le tag `dev` (sans toucher `latest`).
- `bin/release.sh` (sans argument) : utilise le tag git posé sur HEAD ; s'il n'y en a pas, ne fait rien.

## Mettre à jour
```bash
bin/release.sh v1.2.0                                 # poste
ssh vm 'cd ~/daily-budget && docker compose -f compose.prod.yaml --env-file stack.env up -d'
```
`pull_policy: always` récupère les nouvelles images ; le service `migrate` applique les migrations avant que l'API et le worker redémarrent.

## Rollback
Dans `stack.env` sur la VM : `TAG=<tag git précédent>`, puis `up -d`.

## Vérifier
```bash
docker compose -f compose.prod.yaml --env-file stack.env ps   # api/front healthy, migrate exited (0), worker running
curl -s -o /dev/null -w '%{http_code}\n' localhost:3000/          # 200
curl -s -o /dev/null -w '%{http_code}\n' localhost:3000/api/banks # 401
```
