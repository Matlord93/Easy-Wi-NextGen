#!/usr/bin/env bash
set -euo pipefail

catalog_repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
catalog_core_target="${1:?Usage: prepare-minecraft-catalog.sh <core-package-directory>}"
install -d "${catalog_core_target}/resources/minecraft"
for catalog_file in minecraft-server.json paper-versions.json bedrock-server.json; do
  install -m 0644 "${catalog_repo_root}/minecraft/${catalog_file}" "${catalog_core_target}/resources/minecraft/${catalog_file}"
done
