<?php
declare(strict_types=1);
function qa_server_config(): array {
    $path = getenv('KCRM_QA_DB_FILE') ?: __DIR__ . '/.runtime/local-db.json';
    $config = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($config['marker'] ?? '') !== 'KCRM_QA_LOCAL_SERVER' || ($config['host'] ?? '') !== '127.0.0.1') throw new RuntimeException('Not a loopback QA server');
    return $config;
}
function qa_database_name(string $name): string {
    if (!preg_match('/^kcrmqa_[a-z0-9_]+$/D', $name)) throw new RuntimeException('Refusing non-QA database');
    return $name;
}
function qa_connect(?string $name = null): PDO {
    $c=qa_server_config();
    $dsn='mysql:host=127.0.0.1;port='.(int)$c['port'].';charset=utf8mb4'.($name ? ';dbname='.qa_database_name($name) : '');
    $db=new PDO($dsn,$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>3]);
    $db->exec("SET time_zone='+00:00'");return $db;
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $action=$argv[1] ?? 'probe';$db=qa_connect();
        if ($action==='probe') echo json_encode(['ok'=>true,'version'=>$db->query('SELECT VERSION()')->fetchColumn(),'timezone'=>$db->query('SELECT @@session.time_zone')->fetchColumn()]);
        elseif ($action==='create') {
            $name=qa_database_name($argv[2] ?? '');
            $query=$db->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$query->execute([$name]);
            if ((int)$query->fetchColumn()!==0) throw new RuntimeException('Refusing to reuse an existing database');
            $db->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            echo json_encode(['ok'=>true,'database'=>$name,'empty'=>true]);
        } else throw new RuntimeException('Unknown QA database action');
    } catch(Throwable $e) { fwrite(STDERR, 'QA database operation failed: '.preg_replace('/password[^\s]*/i','[redacted]',$e->getMessage())."\n");exit(1); }
}
