#!/usr/bin/env bash
set -e

PORT_TO_USE="${PORT:-10000}"

sed -ri "s/^Listen [0-9]+/Listen ${PORT_TO_USE}/" /etc/apache2/ports.conf
sed -ri "s/:80>/:${PORT_TO_USE}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground