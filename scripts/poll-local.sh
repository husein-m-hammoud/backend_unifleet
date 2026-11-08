#!/usr/bin/env bash
#
# LOCAL scheduler supervisor (dev machines only).
#
# On the server this job belongs in crontab instead:
#     * * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
#
# Locally we keep `schedule:work` alive in a restart loop, because that process
# dies on any crash — and a dead scheduler is invisible: live positions keep
# flowing from the last poll while the vehicle roster silently freezes. That is
# exactly how 74 Alrakeen vehicles went missing for a month (Sep–Oct 2026).
#
# Usage:
#     ./scripts/poll-local.sh              # run in the foreground (Ctrl-C to stop)
#     nohup ./scripts/poll-local.sh &      # keep running after the terminal closes
#     tail -f storage/logs/scheduler-local.log
#
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1

LOG="storage/logs/scheduler-local.log"
LOCK="storage/framework/scheduler-local.pid"

mkdir -p "$(dirname "$LOG")" "$(dirname "$LOCK")"

# Refuse to start a second copy: two schedulers means every poll runs twice.
# (`withoutOverlapping()` guards each command, but double-ticking still wastes
# API calls and muddies the diagnostics.)
if [[ -f "$LOCK" ]] && kill -0 "$(cat "$LOCK" 2>/dev/null)" 2>/dev/null; then
    echo "Already running as PID $(cat "$LOCK"). Stop it first, or delete $LOCK if stale."
    exit 1
fi
echo $$ > "$LOCK"

cleanup() {
    echo "[$(date '+%F %T')] supervisor stopping (PID $$)" | tee -a "$LOG"
    rm -f "$LOCK"
    # Kill the child scheduler too, so Ctrl-C does not leave it orphaned.
    [[ -n "${CHILD:-}" ]] && kill "$CHILD" 2>/dev/null
    exit 0
}
trap cleanup INT TERM

echo "[$(date '+%F %T')] supervisor started (PID $$) — logging to $LOG"
echo "[$(date '+%F %T')] supervisor started (PID $$)" >> "$LOG"

while true; do
    php artisan schedule:work >> "$LOG" 2>&1 &
    CHILD=$!
    wait "$CHILD"
    code=$?

    # 0 / 130 / 143 mean we asked it to stop — anything else is a crash.
    if [[ $code -eq 0 || $code -eq 130 || $code -eq 143 ]]; then
        cleanup
    fi

    echo "[$(date '+%F %T')] schedule:work exited (code $code) — restarting in 5s" | tee -a "$LOG"
    sleep 5
done
