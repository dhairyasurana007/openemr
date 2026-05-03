#!/bin/sh
# Render / any host: OpenEMR reads DB host from sites/default/sqlconf.php. If a prior
# deploy wrote a Docker-only hostname (e.g. openemr-mysql), override it from MYSQL_HOST
# before openemr.sh runs. Also default site URL from Render when unset.
#
# Lives under contrib/render/ (not docker/) because .dockerignore excludes the docker/ tree.
set -eu

OE_ROOT="/var/www/localhost/htdocs/openemr"
SQLCONF="${OE_ROOT}/sites/default/sqlconf.php"

export OPENEMR_SETTING_site_addr_oath="${OPENEMR_SETTING_site_addr_oath:-${RENDER_EXTERNAL_URL:-}}"
export MYSQL_PORT="${MYSQL_PORT:-3306}"

# Render starts the web and DB services in parallel; openemr.sh runs "quick setup" immediately.
# If MySQL is not listening yet, auto_configure fails and you stay on setup.php forever.
# Wait until TCP to MYSQL_HOST succeeds (default up to 10 minutes).
MYSQL_WAIT_MAX_SECONDS="${MYSQL_WAIT_MAX_SECONDS:-600}"
if [ -n "${MYSQL_HOST:-}" ]; then
    echo "render-openemr-bootstrap: waiting for MySQL at ${MYSQL_HOST}:${MYSQL_PORT} (max ${MYSQL_WAIT_MAX_SECONDS}s)..."
    elapsed=0
    while [ "$elapsed" -lt "$MYSQL_WAIT_MAX_SECONDS" ]; do
        if php <<'PHP'
<?php
declare(strict_types=1);
$host = getenv('MYSQL_HOST') ?: '';
$port = (int) (getenv('MYSQL_PORT') ?: '3306');
if ($host === '') {
    exit(1);
}
$errno = 0;
$errstr = '';
$fp = @fsockopen($host, $port, $errno, $errstr, 2);
if ($fp !== false) {
    fclose($fp);
    exit(0);
}
exit(1);
PHP
        then
            echo "render-openemr-bootstrap: MySQL is accepting connections."
            break
        fi
        if [ "$elapsed" -gt 0 ] && [ $((elapsed % 30)) -eq 0 ]; then
            php <<'PHP'
<?php
declare(strict_types=1);
$host = getenv('MYSQL_HOST') ?: '';
$port = (int) (getenv('MYSQL_PORT') ?: '3306');
$errno = 0;
$errstr = '';
@fsockopen($host, $port, $errno, $errstr, 2);
$errOneLine = str_replace(["\r", "\n"], ' ', (string) $errstr);
fwrite(STDOUT, "render-openemr-bootstrap: TCP probe to {$host}:{$port} -> errno={$errno} errstr={$errOneLine}\n");
$e = (string) $errstr;
if ($e !== '' && (str_contains($e, 'getaddrinfo') || str_contains($e, 'Name does not resolve') || str_contains($e, 'Temporary failure in name resolution'))) {
    fwrite(STDERR, "render-openemr-bootstrap: HINT: MYSQL_HOST must resolve inside this container. "
        . "On Render use the Internal Hostname from your private MariaDB/MySQL service (not Docker Compose names like openemr-mysql).\n");
}
PHP
            echo "render-openemr-bootstrap: still waiting (${elapsed}s elapsed)..."
        fi
        sleep 2
        elapsed=$((elapsed + 2))
    done
    if ! php <<'PHP'
<?php
declare(strict_types=1);
$host = getenv('MYSQL_HOST') ?: '';
$port = (int) (getenv('MYSQL_PORT') ?: '3306');
if ($host === '') {
    exit(1);
}
$errno = 0;
$errstr = '';
$fp = @fsockopen($host, $port, $errno, $errstr, 2);
if ($fp !== false) {
    fclose($fp);
    exit(0);
}
exit(1);
PHP
    then
        echo "render-openemr-bootstrap: WARNING: MySQL still not reachable after ${MYSQL_WAIT_MAX_SECONDS}s; openemr.sh may fail quick setup. Check DB service and MYSQL_HOST." >&2
    fi
fi

if [ -n "${MYSQL_HOST:-}" ] && [ -f "$SQLCONF" ]; then
    php <<'PHP'
<?php
declare(strict_types=1);

$host = getenv('MYSQL_HOST');
if ($host === false || $host === '') {
    exit(0);
}
$f = '/var/www/localhost/htdocs/openemr/sites/default/sqlconf.php';
$c = file_get_contents($f);
if ($c === false) {
    fwrite(STDERR, "render-openemr-bootstrap: cannot read sqlconf.php\n");
    exit(1);
}
$escaped = str_replace(["\\", "'"], ["\\\\", "\\'"], $host);
// Match includes trailing ';' — replacement must end with ';' or PHP errors on the next line ($port).
$replacement = '$host   = \'' . $escaped . '\';';
$c2 = preg_replace('/^\$host\s*=\s*[^;]+;/m', $replacement, $c, 1);
if ($c2 === null) {
    fwrite(STDERR, "render-openemr-bootstrap: preg_replace failed\n");
    exit(1);
}
if (file_put_contents($f, $c2) === false) {
    fwrite(STDERR, "render-openemr-bootstrap: cannot write sqlconf.php\n");
    exit(1);
}
$lint = shell_exec('php -l ' . escapeshellarg($f) . ' 2>&1');
if ($lint === null || !str_contains($lint, 'No syntax errors')) {
    fwrite(STDERR, "render-openemr-bootstrap: sqlconf.php failed PHP lint after host patch; restoring backup.\n");
    file_put_contents($f, $c);
    fwrite(STDERR, (string) $lint);
    exit(1);
}
PHP
fi

# Repo-based images (COPY . .) may not ship /var/www/localhost/htdocs/auto_configure.php.
# Run the same Installer::quick_install() path as setup.php when env matches flex/docker conventions.
echo "render-openemr-bootstrap: openemr-auto-install.php (no-op if already configured or MANUAL_SETUP=yes)..."
if ! php "${OE_ROOT}/contrib/render/openemr-auto-install.php"; then
    echo "render-openemr-bootstrap: WARNING: openemr-auto-install.php failed. Starting Apache anyway so you can use setup.php or read logs. Fix MYSQL_* / credentials and redeploy." >&2
fi

echo "render-openemr-bootstrap: openemr-seed-standard-role-users.php (no-op unless OPENEMR_AUTO_SEED_STANDARD_ROLES is enabled)..."
if ! php "${OE_ROOT}/contrib/render/openemr-seed-standard-role-users.php"; then
    echo "render-openemr-bootstrap: WARNING: openemr-seed-standard-role-users.php failed (check ACL group titles match your locale)." >&2
fi

echo "render-openemr-bootstrap: openemr-seed-copilot-demo-schedule.php (no-op unless OPENEMR_AUTO_SEED_COPILOT_DEMO_SCHEDULE is enabled)..."
if ! php "${OE_ROOT}/contrib/render/openemr-seed-copilot-demo-schedule.php"; then
    echo "render-openemr-bootstrap: WARNING: openemr-seed-copilot-demo-schedule.php failed (check facility/calendar category exist)." >&2
fi

# Render private service mesh: Blueprint may set CLINICAL_COPILOT_AGENT_PRIVATE_HOSTPORT (host:port).
# Derive a full URL for PHP / modules without string templating in render.yaml.
if [ -n "${CLINICAL_COPILOT_AGENT_PRIVATE_HOSTPORT:-}" ]; then
    export CLINICAL_COPILOT_AGENT_BASE_URL="http://${CLINICAL_COPILOT_AGENT_PRIVATE_HOSTPORT}"
fi

cd "$OE_ROOT"
exec ./openemr.sh
