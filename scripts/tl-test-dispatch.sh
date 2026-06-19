#!/usr/bin/env bash
# tl-test-dispatch.sh <step> — fire CLI commands to all tl-test tmux panes.
# Each pane runs tl-run, which logs JSONL and writes a done marker.
#
# Steps:
#   1 — ticketlens fetch CNV1-2 (all 6 users — verifies Jira connection + tier gate)
#   2 — ticketlens triage CNV1-2 --push (pro + team only — populates triage queue)
#   3 — ticketlens stats (all 6 users — reads local token-savings ledger)
#
# Usage: ./scripts/tl-test-dispatch.sh 1
set -euo pipefail

SESSION="tl-test"
STEP="${1:?usage: tl-test-dispatch.sh <step>}"
DONE_DIR="/tmp/tl-done"
SCRIPTS_ABS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TRUN="$SCRIPTS_ABS/tl-run"

# Validate step
case "$STEP" in
  1|2|3) ;;
  *) echo "Unknown step: $STEP (valid: 1, 2, 3)"; exit 1 ;;
esac

# Clear done markers for this step
rm -f "${DONE_DIR}"/*-step"${STEP}" 2>/dev/null || true

dispatch() {
  local window="$1" label="$2" cmd="$3"
  tmux send-keys -t "${SESSION}:${window}" "$TRUN $STEP $label $cmd" Enter
}

echo "→ Dispatching step $STEP to tmux session $SESSION..."

case "$STEP" in
  1)
    # All 6 users: basic fetch — verifies Jira connection, free-tier gate
    dispatch tl-free  free  "ticketlens fetch CNV1-2"
    dispatch tl-pro   pro   "ticketlens fetch CNV1-2"
    dispatch tl-mgr   mgr   "ticketlens fetch CNV1-2"
    dispatch tl-mbr1  mbr1  "ticketlens fetch CNV1-2"
    dispatch tl-mbr2  mbr2  "ticketlens fetch CNV1-2"
    dispatch tl-mbr3  mbr3  "ticketlens fetch CNV1-2"
    EXPECT=6
    ;;
  2)
    # Pro + team users: triage push — creates triage queue entries in console
    # Free user intentionally excluded (no push permission)
    dispatch tl-pro   pro   "ticketlens triage CNV1-2 --push"
    dispatch tl-mgr   mgr   "ticketlens triage CNV1-2 --push"
    dispatch tl-mbr1  mbr1  "ticketlens triage CNV1-2 --push"
    dispatch tl-mbr2  mbr2  "ticketlens triage CNV1-2 --push"
    dispatch tl-mbr3  mbr3  "ticketlens triage CNV1-2 --push"
    EXPECT=5
    ;;
  3)
    # All 6: stats — reads local ledger, no server push
    dispatch tl-free  free  "ticketlens stats"
    dispatch tl-pro   pro   "ticketlens stats"
    dispatch tl-mgr   mgr   "ticketlens stats"
    dispatch tl-mbr1  mbr1  "ticketlens stats"
    dispatch tl-mbr2  mbr2  "ticketlens stats"
    dispatch tl-mbr3  mbr3  "ticketlens stats"
    EXPECT=6
    ;;
esac

# Poll until all expected done markers appear
echo "→ Waiting for $EXPECT pane(s) to finish step $STEP..."
while true; do
  done_count=$(ls "${DONE_DIR}"/*-step"${STEP}" 2>/dev/null | wc -l | tr -d ' ')
  printf "\r  %d/%d complete" "$done_count" "$EXPECT"
  [[ "$done_count" -ge "$EXPECT" ]] && break
  sleep 2
done

echo ""
log_count=$(wc -l < /tmp/tl-action-log-multitier-test.jsonl 2>/dev/null || echo 0)
echo "→ Step $STEP done. Action log entries: $log_count"
echo "   Log: /tmp/tl-action-log-multitier-test.jsonl"
