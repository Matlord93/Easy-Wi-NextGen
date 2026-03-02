#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/../core"

APP_UI_REFACTOR_INSTANCES=1 php vendor/bin/phpunit --filter AdminInstancesUiRefactorSmokeTest
