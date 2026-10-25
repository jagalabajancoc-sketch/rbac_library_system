<?php
/**
 * Database connection (PDO / MySQL-MariaDB)
 * Update the constants below to match your environment.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'library_management');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

/* One timezone for PHP *and* MySQL so "today", due dates and overdue checks
   (PHP date() vs SQL CURDATE()) can never disagree. */
define('APP_TIMEZONE', 'Asia/Manila');
date_default_timezone_set(APP_TIMEZONE);

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $pdo->exec("SET time_zone = '" . (new DateTime('now', new DateTimeZone(APP_TIMEZONE)))->format('P') . "'");
        } catch (PDOException $e) {
            /* Never print the driver message: it can contain the host and user name. */
            error_log('LMS database connection failed: ' . $e->getMessage());
            http_response_code(503);
            die('The library system is temporarily unavailable. Please try again shortly or contact the administrator.');
        }
        /* Friendly stop (web requests only — the CLI migration must be able to connect) if the
           Borrow Request database update has not been applied yet. */
        if (PHP_SAPI !== 'cli') try {
            $pdo->query("SELECT 1 FROM borrow_request LIMIT 0");
        } catch (PDOException $e) {
            $old = false;
            try { $pdo->query("SELECT 1 FROM loan_request LIMIT 0"); $old = true; } catch (PDOException $e2) {}
            if ($old) {
                http_response_code(503);
                die('<!doctype html><meta charset="utf-8"><title>Database update needed</title>'
                  . '<body style="font-family:system-ui,sans-serif;max-width:640px;margin:12vh auto;padding:0 1rem;line-height:1.5">'
                  . '<h2>One-time database update needed</h2>'
                  . '<p>This version renames <code>loan_request</code> to <code>borrow_request</code>. '
                  . 'Back up your database, then open <strong>phpMyAdmin</strong>, select the '
                  . '<code>' . htmlspecialchars(DB_NAME) . '</code> database, choose <strong>Import</strong>, and import '
                  . '<code>database/migrate_borrow_request.sql</code>.</p>'
                  . '<p>Then reload this page.</p></body>');
            }
        }
    }
    return $pdo;
}
