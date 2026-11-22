#!/usr/bin/env bash
set -euo pipefail
hr_clock_root="$(cd "$(dirname "$0")/.." && pwd -P)"
hr_clock_php="$(command -v php)"
hr_clock_marker='# luvep-hr-auto-clock-out'
hr_clock_existing="$(crontab -l 2>/dev/null || true)"
if [[ "$hr_clock_root" != /var/www/epathways-production ]]; then
    echo 'HR clock maintenance is installed only on the production application path.' >&2
    exit 1
fi
hr_clock_line="* * * * * cd '$hr_clock_root' && '$hr_clock_php' artisan hr:auto-clock-out >> storage/logs/hr-auto-clock-out.log 2>&1 $hr_clock_marker"
printf '%s\n' "$hr_clock_existing" | awk -v marker="$hr_clock_marker" 'index($0, marker) == 0' | { cat; printf '%s\n' "$hr_clock_line"; } | crontab -
echo 'HR automatic clock-out installed (every minute, ten working hours for new shifts).'
