<?php
/** Run setup.php, setup.php --status or setup.php --backup while staff are signed out. */
require_once __DIR__.'/maintenance.php';
$p = null; $lock = false;
try {
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['', '--status', '--backup'], true)) throw new RuntimeException('Usage: php database/setup.php [--status|--backup]');
    $p = maintenance_connection();
    if (stripos($p->getAttribute(PDO::ATTR_SERVER_VERSION), 'MariaDB') === false) throw new RuntimeException('MariaDB 10.4+ is required by these migrations.');
    $exists = $p->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
    $exists->execute([DB_NAME]); $found = (bool)$exists->fetchColumn();
    if (!$found && $mode !== '') { echo "Database does not exist; run setup.php to install.\n"; exit($mode === '--status' ? 0 : 1); }
    $lockQuery = $p->prepare('SELECT GET_LOCK(?, 5)');
    $lockName = 'library-setup-'.substr(hash('sha256',DB_NAME),0,32);
    $lockQuery->execute([$lockName]);
    if ((int)$lockQuery->fetchColumn() !== 1) throw new RuntimeException('Another database update is running. Try again later.');
    $lock = true;
    if (!$found) $p->exec('CREATE DATABASE '.database_identifier(DB_NAME).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $p->exec('USE '.database_identifier(DB_NAME));
    $tables = $p->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $fresh = !$tables;
    $history = in_array('schema_migrations',$tables,true)
        ? $p->query('SELECT Migration, Checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    $pending = [];
    foreach (migration_files() as $file) {
        $hash = hash_file('sha256',__DIR__.'/'.$file);
        if (isset($history[$file]) && !hash_equals($history[$file],$hash)) throw new RuntimeException("Applied migration $file was edited. Restore it and create a new migration for new changes.");
        if (!isset($history[$file])) $pending[$file] = $hash;
        if ($mode === '--status') echo (isset($history[$file]) ? 'APPLIED ' : 'PENDING ').$file.PHP_EOL;
    }
    if ($mode === '--status') exit(0);
    if ($mode === '--backup') { backup_database($p); exit(0); }
    if (!$fresh) {
        preg_match_all('/CREATE TABLE (\w+)/', file_get_contents(__DIR__.'/schema.sql'), $matches);
        foreach ($matches[1] as $table) {
            $current = str_replace('loan_request','borrow_request',$table);
            if (!in_array($table,$tables,true) && !in_array($current,$tables,true)) throw new RuntimeException("Existing database is incomplete: $table is missing. Restore the database before updating it.");
        }
        if ((int)$p->query('SELECT COUNT(*) FROM (SELECT LoanDetailID FROM return_detail GROUP BY LoanDetailID HAVING COUNT(*)>1) d')->fetchColumn()) {
            throw new RuntimeException('Duplicate return records need review before migration; no records were changed.');
        }
        if (!$pending) { echo "Database is up to date. No changes needed.\n"; exit(0); }
        backup_database($p);
    } else {
        apply_sql($p,__DIR__.'/schema.sql');
        // Seed immediately and transactionally so a later migration failure can be retried.
        $p->beginTransaction();
        $p->exec("INSERT INTO role (RoleName) VALUES ('Admin'),('Librarian'),('Assistant'),('Member')");
        $p->exec("INSERT INTO policy (PolicyName,IsBorrowable,LoanDays,PenaltyRate) VALUES ('Standard Circulation',1,7,5),('Reference Only',0,0,0)");
        $password = bin2hex(random_bytes(9));
        $q=$p->prepare("INSERT INTO user_account (RoleID,FirstName,LastName,Email,Username,PasswordHash,RegistrationDate) SELECT RoleID,'Library','Administrator','admin@library.local','admin@library.local',?,CURDATE() FROM role WHERE RoleName='Admin'");
        $q->execute([password_hash($password,PASSWORD_DEFAULT)]); $p->commit();
        echo "Administrator: admin@library.local\nTemporary password: $password\nChange it after signing in.\n";
    }
    $p->exec('CREATE TABLE IF NOT EXISTS schema_migrations (Migration VARCHAR(100) PRIMARY KEY, Checksum CHAR(64) NOT NULL, AppliedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
    foreach ($pending as $file=>$hash) {
        if (str_ends_with($file,'.php')) require __DIR__.'/'.$file;
        else apply_sql($p,__DIR__.'/'.$file);
        $q=$p->prepare('INSERT INTO schema_migrations (Migration,Checksum) VALUES (?,?)'); $q->execute([$file,$hash]);
        echo "Applied $file\n";
    }
    echo "Database setup complete. Existing accounts and library records were preserved.\n";
} catch (Throwable $e) {
    if ($p && $p->inTransaction()) $p->rollBack();
    fwrite(STDERR,'Setup stopped: '.$e->getMessage()."\nDDL may already be applied. Resolve the issue and rerun; completed migrations are recorded.\n");
    exit(1);
} finally {
    if ($p && $lock) { $q=$p->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lockName]); }
}
