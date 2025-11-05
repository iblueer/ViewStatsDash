# update_to_server.sh

#!/usr/bin/env bash
set -euo pipefail

REMOTE_USER="${REMOTE_USER:-root}"
REMOTE_HOST="ssh.maemo.cc" #博客服务器IP地址或域名
REMOTE="${REMOTE_USER}@${REMOTE_HOST}"
# REMOTE_DIR="/root/app/typecho/usr/plugins/ViewStatsDash"
TYPECHO_INSTALL_DIR="/root/app/typecho"
PLUGIN_NAME="$(basename "$(cd "$(dirname "$0")" && pwd)")"
REMOTE_DIR="$TYPECHO_INSTALL_DIR/usr/plugins/$PLUGIN_NAME"
LOCAL_DIR="$(cd "$(dirname "$0")" && pwd)"

ssh "$REMOTE" "rm -rf \"$REMOTE_DIR\" && mkdir -p \"$REMOTE_DIR\""
rsync -av --delete \
  --exclude '.git/' \
  --exclude '.github/' \
  --exclude '.DS_Store' \
  "$LOCAL_DIR"/ \
  "$REMOTE":"$REMOTE_DIR"/