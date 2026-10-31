#!/bin/sh
# Waits for MariaDB, runs ONA's own CLI installer on first start, and keeps
# www/local/config/database_settings.inc.php in sync with the environment.
set -eu

: "${ONA_DB_HOST:=db}"
: "${ONA_DB_ROOT_PASSWORD:?set ONA_DB_ROOT_PASSWORD}"
: "${ONA_DB_USER:=ona_sys}"
: "${ONA_DB_PASSWORD:?set ONA_DB_PASSWORD}"
: "${ONA_DB_CONTEXT:=default}"
: "${ONA_DEFAULT_DOMAIN:=example.com}"

DBNAME="ona_${ONA_DB_CONTEXT}"
CONF=/opt/ona/www/local/config/database_settings.inc.php
export ONA_DB_HOST ONA_DB_ROOT_PASSWORD ONA_DB_USER ONA_DB_PASSWORD DBNAME

dbq() {
  php -r '
    mysqli_report(MYSQLI_REPORT_OFF);
    $db = @new mysqli(getenv("ONA_DB_HOST"), "root", getenv("ONA_DB_ROOT_PASSWORD"));
    if ($db->connect_errno) exit(2);
    $r = $db->query($argv[1]);
    if ($r === false) { fwrite(STDERR, $db->error . "\n"); exit(3); }
    if ($r instanceof mysqli_result) { $row = $r->fetch_row(); echo $row ? $row[0] : ""; }
  ' "$1"
}

echo "ona: waiting for database at ${ONA_DB_HOST}..."
i=0
until dbq "SELECT 1" >/dev/null 2>&1; do
  i=$((i + 1))
  [ "$i" -gt 60 ] && { echo "ona: database not reachable" >&2; exit 1; }
  sleep 2
done

installed=$(dbq "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DBNAME}' AND table_name='sys_config'")
if [ "$installed" != "1" ]; then
  echo "ona: no ONA schema in ${DBNAME}, running install/installcli.php"
  rm -f "$CONF"
  # Answers, in order: view license, DB host, DB admin, admin password,
  # app DB user, app DB password, database name (ona_ prefix added), domain.
  printf 'n\n%s\nroot\n%s\n%s\n%s\n%s\n%s\n' \
    "$ONA_DB_HOST" "$ONA_DB_ROOT_PASSWORD" "$ONA_DB_USER" "$ONA_DB_PASSWORD" \
    "$ONA_DB_CONTEXT" "$ONA_DEFAULT_DOMAIN" \
    | php /opt/ona/install/installcli.php
  [ -f "$CONF" ] || { echo "ona: install failed" >&2; exit 1; }
fi

# The installer only grants the app user access from 'localhost' and the DB
# host name; from another container we connect from an arbitrary address.
dbq "CREATE USER IF NOT EXISTS '${ONA_DB_USER}'@'%' IDENTIFIED BY '${ONA_DB_PASSWORD}'"
dbq "ALTER USER '${ONA_DB_USER}'@'%' IDENTIFIED BY '${ONA_DB_PASSWORD}'"
dbq "GRANT ALL PRIVILEGES ON \`${DBNAME}\`.* TO '${ONA_DB_USER}'@'%'"

php -r '
  $c = ["DEFAULT" => [
    "databases" => [[
      "db_type" => "mysqli", "db_host" => getenv("ONA_DB_HOST"),
      "db_login" => getenv("ONA_DB_USER"), "db_passwd" => getenv("ONA_DB_PASSWORD"),
      "db_database" => getenv("DBNAME"), "db_debug" => false,
    ]],
    "description" => "Default data context", "context_color" => "#D3DBFF",
  ]];
  file_put_contents($argv[1], "<?php\n\n\$ona_contexts=" . var_export($c, true) . ";\n\n?>");
' "$CONF"
chown www-data "$CONF"
chmod 640 "$CONF"
touch /var/log/ona.log && chown www-data /var/log/ona.log

exec docker-php-entrypoint "$@"
