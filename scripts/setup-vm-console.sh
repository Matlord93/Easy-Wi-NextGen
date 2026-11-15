#!/usr/bin/env bash
# Source-checkout wrapper; core releases carry their own complete setup helper.
set -euo pipefail
vm_setup_root="$(cd "$(dirname "$0")/.." && pwd)"
exec bash "$vm_setup_root/core/tools/console/setup-vm-console.sh" "$@"
