#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source_dir="$project_root/resources/social-workspace"
destination="${1:-$project_root/storage/app/private/social-workspace-test/v1}"

test -s "$source_dir/index.html"
mkdir -p "$destination"
# Keep generated exports and other runtime files; replace only versioned assets.
rsync -rlt --exclude='/exports/' --exclude='*.py' --exclude='*.md' \
  "$source_dir/" "$destination/"
