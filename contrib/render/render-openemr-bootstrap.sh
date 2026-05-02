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

if [ -n "${MYSQL_HOST:-}" ] && [ -f "$SQLCONF" ]; then
    php <<'PHP'
<?php
declare(strict_types=1);

$host = getenv('MYSQL_HOST');
if ($host === false || $host === '') {
    return;
}
$f = '/var/www/localhost/htdocs/openemr/sites/default/sqlconf.php';
$c = file_get_contents($f);
if ($c === false) {
    fwrite(STDERR, "render-openemr-bootstrap: cannot read sqlconf.php\n");
    exit(1);
}
$escaped = str_replace(["\\", "'"], ["\\\\", "\\'"], $host);
$replacement = '$host   = \'' . $escaped . '\'';
$c2 = preg_replace('/^\$host\s*=\s*[^;]+;/m', $replacement, $c, 1);
if ($c2 === null) {
    fwrite(STDERR, "render-openemr-bootstrap: preg_replace failed\n");
    exit(1);
}
if (file_put_contents($f, $c2) === false) {
    fwrite(STDERR, "render-openemr-bootstrap: cannot write sqlconf.php\n");
    exit(1);
}
PHP
fi

cd "$OE_ROOT"
exec ./openemr.sh
