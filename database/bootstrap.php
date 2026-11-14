<?php
/**
 * Grape - Bootstrap do banco (idempotente, chamado por bin/start-host.bat)
 *
 * Garante que existam:
 *   1. o banco grape_calls;
 *   2. o usuario da aplicacao (grape), com permissao apenas nesse banco;
 *   3. o schema (database/schema.sql) quando as tabelas ainda nao existem.
 *
 * Credenciais de ADMIN (root) usadas apenas para criar o usuario:
 *   - variaveis de ambiente DATABASE_ADMIN_USERNAME / DATABASE_ADMIN_PASSWORD;
 *   - ou config/local.php (secao database_admin);
 *   - padrao: root com senha vazia (data dir recem-criado pelo mariadb-install-db).
 *
 * Credenciais da APLICACAO:
 *   - DATABASE_USERNAME / DATABASE_PASSWORD (ambiente) ou config/local.php;
 *   - se nenhuma senha estiver definida, uma e gerada aleatoriamente e gravada
 *     em config/local.php (somente se o arquivo ainda nao existir).
 *
 * Se a conexao de admin funcionou com senha VAZIA e a variavel GRAPE_DB_FRESH=1
 * estiver setada (data dir criado agora pelo start-host), o root recebe uma
 * senha aleatoria para nao ficar aberto mesmo em loopback.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use GamingCalls\Config\Config;

date_default_timezone_set('America/Sao_Paulo');

Config::load(__DIR__ . '/../config/config.php');

function envOr(string $key, string $fallback = ''): string
{
    $v = getenv($key);
    return $v === false || $v === '' ? $fallback : (string) $v;
}

function randomPassword(): string
{
    return bin2hex(random_bytes(16));
}

$dsnHost = Config::get('database.host', '127.0.0.1');
$dsnPort = (int) Config::get('database.port', 3306);
$dbName = Config::get('database.database', 'grape_calls');

// ------------------------------------------------------------------
// 1. Credenciais da aplicacao
// ------------------------------------------------------------------
$appUser = envOr('DATABASE_USERNAME', (string) Config::get('database.username', 'grape'));
$appPass = envOr('DATABASE_PASSWORD', (string) Config::get('database.password', ''));
$generated = false;

if ($appPass === '') {
    $appPass = randomPassword();
    $generated = true;
}

// ------------------------------------------------------------------
// 2. Conexao de admin (root)
// ------------------------------------------------------------------
$adminUser = envOr('DATABASE_ADMIN_USERNAME', (string) Config::get('database_admin.username', 'root'));
$adminPass = envOr('DATABASE_ADMIN_PASSWORD', (string) Config::get('database_admin.password', ''));
$adminPasswordWasEmpty = ($adminPass === '');

try {
    $admin = new PDO(
        "mysql:host={$dsnHost};port={$dsnPort};charset=utf8mb4",
        $adminUser,
        $adminPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 10,
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "Bootstrap: nao conectei como admin '{$adminUser}' em {$dsnHost}:{$dsnPort}." . PHP_EOL);
    fwrite(STDERR, "Defina DATABASE_ADMIN_USERNAME / DATABASE_ADMIN_PASSWORD (ou config/local.php secao database_admin)." . PHP_EOL);
    fwrite(STDERR, "Detalhe: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

// ------------------------------------------------------------------
// 3. Banco + usuario da aplicacao
// ------------------------------------------------------------------
$admin->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$privileges = 'SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, REFERENCES, INDEX, ALTER';
$hosts = ['127.0.0.1', 'localhost'];

$create = $admin->prepare("CREATE USER IF NOT EXISTS :user@:host IDENTIFIED BY :pass");
$alter = $admin->prepare("ALTER USER :user@:host IDENTIFIED BY :pass");
$grant = $admin->prepare("GRANT {$privileges} ON `{$dbName}`.* TO :user@:host");

foreach ($hosts as $h) {
    $create->execute([':user' => $appUser, ':host' => $h, ':pass' => $appPass]);
    $alter->execute([':user' => $appUser, ':host' => $h, ':pass' => $appPass]);
    $grant->execute([':user' => $appUser, ':host' => $h]);
}

// ------------------------------------------------------------------
// 4. Senha vazia do root em data dir recem-criado: fecha essa porta
// ------------------------------------------------------------------
if ($adminPasswordWasEmpty && getenv('GRAPE_DB_FRESH') === '1') {
    $rootPass = randomPassword();
    foreach ($hosts as $h) {
        try {
            $admin->exec("ALTER USER 'root'@'{$h}' IDENTIFIED BY '{$rootPass}'");
        } catch (PDOException $e) {
            // conta root@host pode nao existir em toda instalacao
        }
    }
    if (defined('STDERR')) {
        fwrite(STDERR, "Bootstrap: senha do root definida automaticamente (data dir novo). " . PHP_EOL);
        fwrite(STDERR, "  root: {$rootPass}" . PHP_EOL);
        fwrite(STDERR, "Guarde esta senha (ou use config/local.php secao database_admin)." . PHP_EOL);
    }
} elseif ($adminPasswordWasEmpty) {
    fwrite(STDERR, "AVISO: conectei como root SEM senha em {$dsnHost}. Defina uma senha para o root." . PHP_EOL);
}

// ------------------------------------------------------------------
// 5. Salva a senha gerada da aplicacao em config/local.php (se ausente)
// ------------------------------------------------------------------
$localPath = __DIR__ . '/../config/local.php';
if ($generated && !file_exists($localPath)) {
    $template = <<<PHP
<?php
/**
 * Grape - Configuracao local (gerada automaticamente pelo bootstrap).
 * NAO versione este arquivo.
 */

return [
    'database' => [
        'username' => '{$appUser}',
        'password' => '{$appPass}',
    ],
];
PHP;
    @mkdir(dirname($localPath), 0777, true);
    file_put_contents($localPath, $template . PHP_EOL);
    echo "Bootstrap: credenciais da aplicacao gravadas em config/local.php" . PHP_EOL;
}

// ------------------------------------------------------------------
// 6. Schema (importa database/schema.sql se as tabelas nao existem)
// ------------------------------------------------------------------
try {
    $app = new PDO(
        "mysql:host={$dsnHost};port={$dsnPort};dbname={$dbName};charset=utf8mb4",
        $appUser,
        $appPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "Bootstrap: usuario '{$appUser}' criado, mas a conexao falhou: " . $e->getMessage() . PHP_EOL);
    exit(1);
}

$roomsExists = $app->query("SHOW TABLES LIKE 'rooms'")->fetchColumn();
if ($roomsExists === false) {
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    if ($sql === false) {
        fwrite(STDERR, "Bootstrap: database/schema.sql nao encontrado." . PHP_EOL);
        exit(1);
    }
    // schema.sql e DDL simples, sem DELIMITER: cada terminacao ";" e um comando.
    foreach (preg_split('/;[\r\n]+/', $sql) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') {
            continue;
        }
        $app->exec($stmt);
    }
    echo "Bootstrap: schema importado em `{$dbName}`" . PHP_EOL;
} else {
    echo "Bootstrap: schema ja existe em `{$dbName}`" . PHP_EOL;
}

echo "Bootstrap: OK (usuario='{$appUser}', banco='{$dbName}')" . PHP_EOL;
