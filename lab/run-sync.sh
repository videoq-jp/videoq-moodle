#!/bin/sh
# Only the VideoQ task: no other Moodle jobs or outgoing notifications are run.
set -u
cd /var/www/html
trap 'exit 0' TERM INT
while :; do
  if php admin/cli/scheduled_task.php '--execute=\local_videoq\task\synchronize'; then
    date +%s > /tmp/videoq-sync-ready
  fi
  sleep 30 &
  wait $! || exit 0
done
