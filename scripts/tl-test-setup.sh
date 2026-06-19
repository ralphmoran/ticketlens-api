#!/usr/bin/env bash
# tl-test-setup.sh — create isolated HOME dirs + launch tl-test tmux session
set -euo pipefail

SESSION="tl-test"
SCRIPTS_ABS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Parallel arrays: window name, home dir, prompt label
WINDOWS=(tl-free  tl-pro  tl-mgr  tl-mbr1  tl-mbr2  tl-mbr3)
HOMES=(/tmp/tl-homes/free  /tmp/tl-homes/pro  /tmp/tl-homes/mgr  /tmp/tl-homes/mbr1  /tmp/tl-homes/mbr2  /tmp/tl-homes/mbr3)
LABELS=("[FREE]"  "[PRO]"  "[MGR]"  "[MBR1]"  "[MBR2]"  "[MBR3]")

# ── 1. Create isolated HOME dirs ───────────────────────────────────────────────
echo "→ Creating isolated CLI home dirs..."
SRC_PROFILES="$HOME/.ticketlens/profiles.json"

for i in "${!WINDOWS[@]}"; do
  dir="${HOMES[$i]}"
  mkdir -p "$dir/.ticketlens"
  if [[ -f "$SRC_PROFILES" ]]; then
    cp "$SRC_PROFILES" "$dir/.ticketlens/profiles.json"
  fi
  echo "  $dir ✓"
done

# ── 2. Initialise action log + done markers ────────────────────────────────────
echo "→ Initialising action log..."
rm -f /tmp/tl-action-log-multitier-test.jsonl
rm -rf /tmp/tl-done
mkdir -p /tmp/tl-done

# ── 3. Kill any prior tl-test session ─────────────────────────────────────────
tmux kill-session -t "$SESSION" 2>/dev/null || true

# ── 4. Launch tmux session ────────────────────────────────────────────────────
echo "→ Launching tmux session: $SESSION"
command -v tmux >/dev/null 2>&1 || { echo "tmux not installed — brew install tmux"; exit 1; }

for i in "${!WINDOWS[@]}"; do
  name="${WINDOWS[$i]}"
  dir="${HOMES[$i]}"
  label="${LABELS[$i]}"

  if [[ $i -eq 0 ]]; then
    tmux new-session -d -s "$SESSION" -n "$name" -x 220 -y 50
  else
    tmux new-window -t "$SESSION" -n "$name"
  fi

  # Set isolated HOME + add tl-run to PATH; PS1 identifies the user
  tmux send-keys -t "${SESSION}:${name}" \
    "export HOME='${dir}' PATH='${SCRIPTS_ABS}':\"\$PATH\" PS1='${label} \$ '" Enter
done

# Admin window — real HOME, for artisan/sail commands
tmux new-window -t "$SESSION" -n "admin"
tmux send-keys -t "${SESSION}:admin" \
  "cd '${SCRIPTS_ABS}/..' && export PS1='[ADMIN] \$ '" Enter

tmux select-window -t "${SESSION}:${WINDOWS[0]}"

# ── 5. Print attach instructions ──────────────────────────────────────────────
cat <<EOF

══════════════════════════════════════════════════════════════════
  Session: $SESSION
  Windows: ${WINDOWS[*]} admin

  Attach (standard):  tmux attach -t $SESSION
  Attach (iTerm CC):  tmux -CC attach -t $SESSION

  Action log:  /tmp/tl-action-log-multitier-test.jsonl
══════════════════════════════════════════════════════════════════
EOF
