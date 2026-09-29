#!/usr/bin/env bash
# Copies the AI chatbot plug-in from the ERP clone into this mirror (the ERP folder is not a
# git repo). Only the plug-in's own files; never ERP code, never secrets or generated data.
#   bash erp_plugin/sync_from_erp.sh [/d/Workspace/app]
set -euo pipefail
ERP="${1:-/d/Workspace/app}"
HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN=app/controllers/ai_chatbot

rm -rf "$HERE/app" "$HERE/public"
mkdir -p "$HERE/$PLUGIN" "$HERE/app/controllers/routing" "$HERE/app/views/ai_chatbot/api" "$HERE/public/assets/ai_chatbot"
( cd "$ERP/$PLUGIN" && find . -type f \
    ! -name config.local.php \
    ! -path './data/catalog.json.php' ! -path './data/grants.sql.php' ! -path './data/ai_chatbot.log.php' \
    ! -path './data/installed.*' ! -path './data/install.lock' \
    -print0 | while IFS= read -r -d '' f; do mkdir -p "$HERE/$PLUGIN/$(dirname "$f")"; cp "$f" "$HERE/$PLUGIN/$f"; done )
cp "$ERP/app/controllers/routing/inc.ai_chatbot.php" "$HERE/app/controllers/routing/"
cp "$ERP/app/views/ai_chatbot/api/"*.php "$HERE/app/views/ai_chatbot/api/"
cp "$ERP/public/assets/ai_chatbot/"* "$HERE/public/assets/ai_chatbot/"

# refuse to leave anything that looks like a secret in the mirror
if grep -rIlE "AIza[0-9A-Za-z_-]{20,}|ErpAiPlugin_[0-9a-f]{8,}|gsk_[0-9A-Za-z]{20,}" "$HERE"; then
    echo "SECRET FOUND in the mirror - fix before committing" >&2; exit 1
fi
echo "mirrored: $(find "$HERE/app" "$HERE/public" -type f | wc -l) files"
