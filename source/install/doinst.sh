#!/bin/bash
set -euo pipefail
/usr/local/emhttp/plugins/deadlock-guard/scripts/lifecycle install
# Unraid rebuilds crontab from plugin-owned .cron files.
cat > /boot/config/plugins/deadlock-guard/deadlock-guard.cron <<'CRON'
* * * * * /usr/local/emhttp/plugins/deadlock-guard/scripts/lifecycle check 2>&1 | logger -t deadlock-guard
CRON
/usr/local/sbin/update_cron
