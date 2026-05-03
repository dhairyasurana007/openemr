<?php

// This program is free software; you can redistribute it and/or
// modify it under the terms of the GNU General Public License
// as published by the Free Software Foundation; either version 2
// of the License, or (at your option) any later version.
// Set the site ID if required.  This must be done before any database
// access is attempted.

$site_id = '';

if (!empty($_GET['site'])) {
    $site_id = $_GET['site'];
} elseif (is_dir("sites/" . ($_SERVER['HTTP_HOST'] ?? 'default'))) {
    $site_id = ($_SERVER['HTTP_HOST'] ?? 'default');
} else {
    $site_id = 'default';
}

if (empty($site_id) || preg_match('/[^A-Za-z0-9\\-.]/', $site_id)) {
    die("Site ID '" . htmlspecialchars($site_id, ENT_NOQUOTES) . "' contains invalid characters.");
}

// Until MySQL accepts connections, show a friendly wait page (cold start / Render DB boot).
// Set OPENEMR_SKIP_DB_GATE=1 to disable. See public/initializing.html.
if (($_ENV['OPENEMR_SKIP_DB_GATE'] ?? getenv('OPENEMR_SKIP_DB_GATE')) !== '1') {
    $dbHost = $_ENV['MYSQL_HOST'] ?? getenv('MYSQL_HOST');
    if (is_string($dbHost) && $dbHost !== '') {
        $dbPort = (int) (($_ENV['MYSQL_PORT'] ?? getenv('MYSQL_PORT')) ?: 3306);
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($dbHost, $dbPort, $errno, $errstr, 1.5);
        if ($fp === false) {
            header('HTTP/1.1 503 Service Unavailable');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Retry-After: 5');
            $waitFile = __DIR__ . '/public/initializing.html';
            if (is_readable($waitFile)) {
                readfile($waitFile);
            } else {
                header('Content-Type: text/plain; charset=UTF-8');
                echo 'OpenEMR is starting; database is not reachable yet. Retry shortly.';
            }
            exit;
        }
        fclose($fp);
    }
}

require_once "sites/$site_id/sqlconf.php";

if ($config == 1) {
    header("Location: interface/login/login.php?site=$site_id");
} else {
    header("Location: setup.php?site=$site_id");
}
