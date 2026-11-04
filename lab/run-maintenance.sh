#!/bin/sh
# Drain durable recovery/storage tasks without starting another HTTP server.
set -u
cd /workspace/apps/api
trap 'exit 0' TERM INT
while :; do
  if node dist/node.mjs maintenance; then
    node -e 'require("fs").writeFileSync("/tmp/videoq-maintenance-ready",String(Date.now()))'
  fi
  sleep 30 &
  wait $! || exit 0
done
