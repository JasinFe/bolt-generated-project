#!/usr/bin/env bash
# Copie dans src/ les fichiers ajoutés ou modifiés par rapport à FinaKop 1.875.5 (commit « base » de l'arbre de travail).
set -euo pipefail
W="${1:?arbre de travail git}"; ICI="$(cd "$(dirname "$0")" && pwd)"
rm -rf "$ICI/src"; mkdir -p "$ICI/src"
cd "$W"
BASE=$(git rev-list --max-parents=0 HEAD)
{ git diff --name-only "$BASE"; git ls-files --others --exclude-standard; } | sort -u | while read -r f; do
  [ -f "$f" ] || continue
  case "$f" in config/config.php) continue ;; esac
  mkdir -p "$ICI/src/$(dirname "$f")"; cp -p "$f" "$ICI/src/$f"
done
git diff "$BASE" --stat | tail -1
find "$ICI/src" -type f | wc -l
