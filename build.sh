#!/usr/bin/env bash
# ==============================================================================
# DLE Social AutoPost Plugin - Build Wrapper
# ==============================================================================

set -e

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
php "${DIR}/scripts/build.php" "$@"
