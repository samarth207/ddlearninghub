<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Set these to the MySQL database details from your hosting control panel.
define('DB_HOST', 'localhost');
define('DB_NAME', 'u261758575_ddlearn');
define('DB_USER', 'u261758575_ddlearn');
define('DB_PASS', 'm3@G$HxmAr?C');

// Use a leading slash for the application path, or '' when installed at the domain root.
define('BASE_URL', '');

// Keep true in production; use HTTPS for the entire site.
define('SESSION_COOKIE_SECURE', true);
