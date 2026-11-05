#!/bin/sh
set -eu
# Moodle 5.1 checks the real request-directory path before allowing X-Sendfile.
mkdir -p /tmp/requestdir
chmod 1777 /tmp/requestdir
exec moodle-docker-php-entrypoint "$@"
