#!/bin/sh
set -eu

mkdir -p /app/data
php /app/scripts/init.php
exec "$@"
