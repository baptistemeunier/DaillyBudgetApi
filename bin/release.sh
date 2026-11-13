#!/usr/bin/env bash
# Build the api + front images and push them to the private registry.
#   bin/release.sh v1.2.0 -> pushes v1.2.0 + latest (clean working tree required)
#   bin/release.sh dev    -> pushes the "dev" tag (current working tree, no latest)
#   bin/release.sh        -> uses the git tag on HEAD; does nothing if HEAD has no tag
# REGISTRY defaults to ghcr.io/baptistemeunier (override via env or stack.env).
# Works with podman or docker.
set -euo pipefail

cd "$(dirname "$0")/.."
FRONT_DIR=${FRONT_DIR:-../daily-budget-front}

if [[ -z "${REGISTRY:-}" && -f stack.env ]]; then
  REGISTRY=$(sed -n 's/^REGISTRY=//p' stack.env)
fi
REGISTRY=${REGISTRY:-ghcr.io/baptistemeunier}

TAG=${1:-$(git describe --tags --exact-match HEAD 2>/dev/null || true)}

if [[ -z "$TAG" ]]; then
  echo "No tag given and HEAD has no git tag: nothing to release."
  echo "usage: $0 [<tag>|dev]"
  exit 0
fi
if [[ ! "$TAG" =~ ^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$ ]]; then
  echo "Invalid image tag: $TAG" >&2
  exit 2
fi

if [[ "$TAG" == dev ]]; then
  TAGS=(dev)
else
  if [[ -n "$(git status --porcelain)" ]]; then
    echo "Uncommitted changes: refusing to release $TAG (use 'dev' for a work-in-progress image)." >&2
    exit 1
  fi
  TAGS=("$TAG" latest)
fi

ENGINE=$(command -v podman || command -v docker)
FORMAT=()
[[ "$ENGINE" == *podman ]] && FORMAT=(--format docker)  # keep HEALTHCHECK

build_push() {
  local image="$REGISTRY/daily-budget-$1" context=$2 tag args=()
  for tag in "${TAGS[@]}"; do args+=(-t "$image:$tag"); done
  echo "==> $image:{${TAGS[*]}}"
  "$ENGINE" build "${FORMAT[@]}" --platform linux/amd64 "${args[@]}" "$context"
  for tag in "${TAGS[@]}"; do "$ENGINE" push "$image:$tag"; done
}

build_push api .
build_push front "$FRONT_DIR"

echo "Pushed $REGISTRY/daily-budget-{api,front}: ${TAGS[*]}"
