#!/usr/bin/env bash
set -euo pipefail
media_app_root="$(cd "$(dirname "$0")/.." && pwd -P)"
media_php="$(command -v php)"
media_marker='# luvep-media-drive-cleanup'
media_existing="$(crontab -l 2>/dev/null || true)"
# Preserve every unrelated entry. Paths on the production host are fixed and space-free.
if [[ "$media_app_root" != /var/www/epathways-production ]]; then
    echo 'Media Drive maintenance is installed only on the production application path.' >&2
    exit 1
fi
media_line="30 * * * * cd '$media_app_root' && '$media_php' artisan media-drive:prune-abandoned >> storage/logs/media-drive-cleanup.log 2>&1 $media_marker"
printf '%s\n' "$media_existing" | awk -v marker="$media_marker" 'index($0, marker) == 0' | { cat; printf '%s\n' "$media_line"; } | crontab -
echo 'Media Drive abandoned-upload cleanup installed (hourly, 24-hour minimum age).'
