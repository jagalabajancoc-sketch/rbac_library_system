<?php
/** Database tools are CLI-only; never include these in a web request. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Command line only.'); }
require_once __DIR__.'/../config/db.php';
function maintenance_connection(): PDO {
    return new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';charset='.DB_CHARSET, DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}
function database_identifier(string $name): string { return '`'.str_replace('`', '``', $name).'`'; }
function migration_files(): array {
    return ['migrate_borrow_request.php', 'migrate_self_service_kiosk.sql', 'migrate_entry_and_permission.sql',
        'migrate_id_custody.sql', 'migrate_session_collation.sql', 'migrate_desk_borrow.sql',
        'migrate_desk_teacher.sql', 'migrate_release1.sql', 'migrate_feedback.php',
        'migrate_payment_reference.sql'];
}
function apply_sql(PDO $p, string $file): void {
    // Migration files are trusted project SQL, without routines or semicolons in literals.
    $sql = preg_replace('/^\s*--[^\r\n]*/m', '', file_get_contents($file));
    foreach (explode(';', ltrim($sql, "\xEF\xBB\xBF")) as $statement) {
        if (trim($statement) !== '') $p->exec($statement);
    }
}
function mysql_tool(string $tool): string {
    $override = getenv($tool === 'mysqldump' ? 'MYSQLDUMP_PATH' : 'MYSQL_PATH');
    return $override ?: (PHP_OS_FAMILY === 'Windows' ? 'C:/xampp/mysql/bin/'.$tool.'.exe' : $tool);
}
function run_database_tool(array $args, ?string $input = null): void {
    $env = getenv(); $env['MYSQL_PWD'] = DB_PASS;
    $descriptors = [0=>$input ? ['file',$input,'r'] : ['pipe','r'], 1=>['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2=>['pipe','w']];
    $process = proc_open($args, $descriptors, $pipes, null, $env);
    if (!is_resource($process)) throw new RuntimeException('Could not start database tool.');
    if (isset($pipes[0])) fclose($pipes[0]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Database tool failed: '.$error);
}
function backup_database(PDO $p): string {
    $dir = getenv('LIBRARY_BACKUP_DIR') ?: (PHP_OS_FAMILY === 'Windows' ? 'C:/xampp/private/library-backups' : '');
    if (!$dir) throw new RuntimeException('Set LIBRARY_BACKUP_DIR to a private directory outside the web document root.');
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('Cannot create backup directory.');
    $path = str_replace('\\','/', realpath($dir));
    foreach ([realpath(__DIR__.'/..'), PHP_OS_FAMILY === 'Windows' ? 'C:/xampp/htdocs' : null] as $publicRoot) {
        if ($publicRoot && (strcasecmp($path, str_replace('\\','/',$publicRoot)) === 0 || stripos($path.'/', rtrim(str_replace('\\','/',$publicRoot),'/').'/') === 0)) {
            throw new RuntimeException('Backups must be stored outside the web document root.');
        }
    }
    $file = $path.'/'.preg_replace('/[^A-Za-z0-9_-]/','_',DB_NAME).'-'.date('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sql';
    run_database_tool([mysql_tool('mysqldump'), '--host='.DB_HOST, '--port='.DB_PORT, '--user='.DB_USER,
        '--single-transaction', '--routines', '--triggers', '--hex-blob', '--result-file='.$file, DB_NAME]);
    if (!is_file($file) || filesize($file) === 0) throw new RuntimeException('Backup is empty; update stopped.');
    chmod($file,0600);
    file_put_contents($file.'.sha256', hash_file('sha256',$file).PHP_EOL);
    echo "Backup saved: $file\n";
    return $file;
}
