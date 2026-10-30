#!/usr/bin/env bash
# Sets up the Founders Walk site on a Hostinger VPS (Ubuntu 22.04 or 24.04).
#
# Run it as root from the founders-walk folder you uploaded:
#   cd /root/founders-walk && bash deploy/setup-vps.sh
#
# It asks for: the web address (e.g. walk.uncommoncollectiveau.com), the host PIN,
# and the password of the walk@ mailbox. It is safe to run again later to update
# the site files; it keeps the database and your settings.
set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"
WEB=/var/www/founders-walk

[ "$(id -u)" = 0 ] || { echo "Run this as root (or with sudo)."; exit 1; }
[ -f "$SRC/api.php" ] && [ -f "$SRC/index.html" ] || { echo "Run this from the founders-walk folder."; exit 1; }

ask() { # ask VAR "Question" [default] [secret]
  local __v="$1" q="$2" d="${3:-}" s="${4:-}" a=""
  while [ -z "$a" ]; do
    if [ -n "$s" ]; then read -r -s -p "$q: " a; echo; else read -r -p "$q${d:+ [$d]}: " a; fi
    a="${a:-$d}"
  done
  printf -v "$__v" '%s' "$a"
}

echo "== Founders Walk: VPS setup =="
FIRST_RUN=1; WANT_MAIL=0; [ -f "$WEB/config.php" ] && FIRST_RUN=0

# Other sites may already live on this VPS. If something other than nginx is answering
# on port 80 or 443 (Docker, Traefik, Caddy, Apache...), stop before changing anything.
OTHER="$(ss -ltnpH '( sport = :80 or sport = :443 )' 2>/dev/null | grep -v '"nginx"' || true)"
if [ -n "$OTHER" ]; then
  echo
  echo "Another web server is already using port 80 or 443 on this VPS:"
  echo "$OTHER"
  command -v docker >/dev/null && docker ps --format '  {{.Names}}  {{.Image}}  {{.Ports}}' 2>/dev/null || true
  echo
  echo "Nothing has been changed. Copy everything above and send it to Claude, so the"
  echo "site can be added alongside your existing one without breaking it."
  exit 1
fi

ask DOMAIN "Web address for the site" "walk.uncommoncollectiveau.com"
if [ $FIRST_RUN = 1 ]; then
  ask PIN "Host PIN (8+ characters, only you know it)" "" secret
  [ ${#PIN} -ge 8 ] || { echo "The PIN needs at least 8 characters."; exit 1; }
  ask FROM "Mailbox the summaries come from" "walk@uncommoncollectiveau.com"
  ask HOSTMAIL "Where your roll-up goes" "contact@uncommoncollectiveau.com"
  read -r -p "Send summary emails now? (y/N, you can add it later): " WANT_MAIL
  case "$WANT_MAIL" in [yY]*) WANT_MAIL=1 ;; *) WANT_MAIL=0 ;; esac
  if [ $WANT_MAIL = 1 ]; then
  ask SMTP_HOST "That mailbox's SMTP server (Hostinger email: smtp.hostinger.com; Microsoft 365: smtp.office365.com)" "smtp.hostinger.com"
  SMTP_PORT=465; STARTTLS=off
  case "$SMTP_HOST" in *office365*|*outlook*|*gmail*) SMTP_PORT=587; STARTTLS=on ;; esac
  ask SMTP_PASS "Password for $FROM" "" secret
  fi
fi

echo "== Installing web server, PHP and mail tools =="
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq nginx php-fpm php-sqlite3 php-mbstring msmtp msmtp-mta certbot python3-certbot-nginx >/dev/null
PHPV="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
SOCK="/run/php/php$PHPV-fpm.sock"

echo "== Copying the site to $WEB =="
mkdir -p "$WEB/data"
for f in index.html api.php qrcode.min.js qrcode.LICENSE.txt; do
  [ -f "$SRC/$f" ] && install -m 644 "$SRC/$f" "$WEB/$f"
done
chown -R www-data:www-data "$WEB/data"; chmod 750 "$WEB/data"

if [ $FIRST_RUN = 1 ]; then
  # Build config.php from the sample, filling in the answers.
  MAILMODE=log; [ $WANT_MAIL = 1 ] && MAILMODE=mail
  PIN="$PIN" FROM="$FROM" HOSTMAIL="$HOSTMAIL" MAILMODE="$MAILMODE" php -r '
    $c = file_get_contents($argv[1]);
    $set = function ($name, $val) use (&$c) {
      $c = preg_replace("/const $name\s*=\s*[^;]*;/", "const $name = " . var_export($val, true) . ";", $c, 1);
    };
    $set("FW_HOST_PIN", getenv("PIN"));
    $set("FW_FROM_ADDRESS", getenv("FROM"));
    $set("FW_HOST_EMAIL", getenv("HOSTMAIL"));
    $set("FW_MAIL_MODE", getenv("MAILMODE"));
    file_put_contents($argv[2], $c);
  ' "$SRC/config.sample.php" "$WEB/config.php"
  chown root:www-data "$WEB/config.php"; chmod 640 "$WEB/config.php"

fi
if [ $FIRST_RUN = 1 ] && [ $WANT_MAIL = 1 ]; then
  # PHP's mail() hands messages to msmtp, which logs in to the real mailbox.
  # That way summaries pass SPF/DKIM for your domain instead of landing in spam.
  cat > /etc/msmtprc <<EOF
defaults
auth on
tls on
tls_trust_file /etc/ssl/certs/ca-certificates.crt
logfile /var/log/msmtp.log

account walk
host $SMTP_HOST
port $SMTP_PORT
tls_starttls $STARTTLS
from $FROM
user $FROM
password $SMTP_PASS

account default : walk
EOF
  chown root:www-data /etc/msmtprc; chmod 640 /etc/msmtprc
  touch /var/log/msmtp.log; chown www-data:www-data /var/log/msmtp.log
fi
chown root:www-data "$WEB"/*.html "$WEB"/*.php "$WEB"/*.js 2>/dev/null || true

echo "sendmail_path = \"/usr/bin/msmtp -t\"" > "/etc/php/$PHPV/fpm/conf.d/99-founders-walk.ini"

echo "== Configuring nginx for $DOMAIN =="
LISTEN6=""; [ -s /proc/net/if_inet6 ] && LISTEN6="listen [::]:80;"
cat > /etc/nginx/sites-available/founders-walk <<EOF
server {
    listen 80;
    $LISTEN6
    server_name $DOMAIN;
    root $WEB;
    index index.html;
    client_max_body_size 64k;

    # Only the site and its API are public. Settings and the database are not.
    location = /api.php {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$SOCK;
    }
    location ~ \.php\$ { return 404; }
    location ^~ /data/ { return 404; }
    location ~ /\.  { return 404; }
    location ~* \.(sqlite|md|sh|eml)\$ { return 404; }

    location / { try_files \$uri \$uri/ =404; }

    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
}
EOF
ln -sf /etc/nginx/sites-available/founders-walk /etc/nginx/sites-enabled/founders-walk
# Remove nginx's own placeholder site only if it is untouched; a real site in it stays.
if [ -e /etc/nginx/sites-enabled/default ] && grep -q 'root /var/www/html;' /etc/nginx/sites-enabled/default \
   && [ "$(ls /var/www/html 2>/dev/null)" = "index.nginx-debian.html" ]; then
  rm -f /etc/nginx/sites-enabled/default
fi
nginx -t

restart() { systemctl restart "$1" 2>/dev/null || service "$1" restart; }
restart "php$PHPV-fpm"; restart nginx

if command -v ufw >/dev/null && ufw status | grep -q active; then ufw allow 'Nginx Full' >/dev/null; fi

echo "== HTTPS =="
if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "${HOSTMAIL:-admin@$DOMAIN}" --redirect; then
  echo "HTTPS is on."
else
  echo "HTTPS didn't work yet. Check that $DOMAIN points to this server's IP (an A record), wait a few minutes, then run:"
  echo "  certbot --nginx -d $DOMAIN --redirect"
fi

cat <<EOF

Done. Open https://$DOMAIN/?host and enter your PIN.

Check these say "Not found" (they must not be readable):
  https://$DOMAIN/config.php
  https://$DOMAIN/data/founders-walk.sqlite

To send yourself a test email:
  printf 'Subject: Founders Walk test\n\nIt works.' | sudo -u www-data msmtp ${HOSTMAIL:-you@example.com}
EOF
