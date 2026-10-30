#!/usr/bin/env bash
# Starts (or updates) Founders Walk as a Docker app on a server that already runs Traefik,
# like a Hostinger Docker VPS. Run from the founders-walk folder:
#   bash deploy/docker-up.sh
# Safe to rerun after updating the files: walks and settings are kept.
set -euo pipefail
cd "$(dirname "$0")/.."

[ "$(id -u)" = 0 ] || { echo "Run this as root."; exit 1; }
command -v docker >/dev/null || { echo "Docker isn't installed on this server."; exit 1; }
docker ps --format '{{.Image}}' | grep -q '^traefik' || { echo "Traefik isn't running on this server, so this script can't give the site an address. Send this message to Claude."; exit 1; }

DEFAULT_DOMAIN="walk.$(hostname -s).hstgr.cloud"
OLD_DOMAIN="$(sed -n 's/^FW_DOMAIN=//p' .env 2>/dev/null || true)"
read -r -p "Web address for the site [${OLD_DOMAIN:-$DEFAULT_DOMAIN}]: " DOMAIN
DOMAIN="${DOMAIN:-${OLD_DOMAIN:-$DEFAULT_DOMAIN}}"
printf 'FW_DOMAIN=%s\n' "$DOMAIN" > .env

if [ ! -f config.php ]; then
  PIN=""
  while [ ${#PIN} -lt 8 ]; do
    read -r -s -p "Choose a host PIN (8+ characters, only you know it): " PIN; echo
    [ ${#PIN} -ge 8 ] || echo "Needs at least 8 characters."
  done
  PIN="$PIN" docker run --rm -e PIN -v "$PWD:/w" -w /w php:8.3-cli php -r '
    $c = file_get_contents("config.sample.php");
    $set = function ($n, $v) use (&$c) { $c = preg_replace("/const $n\s*=\s*[^;]*;/", "const $n = " . var_export($v, true) . ";", $c, 1); };
    $set("FW_HOST_PIN", getenv("PIN"));
    $set("FW_MAIL_MODE", "log");
    file_put_contents("config.php", $c);'
  chmod 644 config.php
  echo "Settings saved. Summary emails are off for now; walks still work without them."
fi

mkdir -p data && chown -R 33:33 data && chmod 750 data
docker compose -f deploy/docker-compose.yml --env-file .env -p founders-walk up -d

echo
echo "Done. In a minute or two (while HTTPS is set up) open:"
echo "  Host screen:  https://$DOMAIN/?host"
echo "Check this says Forbidden:  https://$DOMAIN/config.php"
