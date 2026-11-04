#!/bin/sh
# Only the VideoQ task: no other Moodle jobs or outgoing notifications are run.
set -u
cd /var/www/html
trap 'exit 0' TERM INT
while :; do
  if php /lab/trust-lab-ca.php && php admin/cli/scheduled_task.php '--execute=\local_videoq\task\synchronize'; then
    date +%s > /tmp/videoq-sync-ready
  fi
  php admin/cli/adhoc_task.php '--classname=\local_videoq\task\export_material' --taskslimit=5
  sleep 30 &
  wait $! || exit 0
done
