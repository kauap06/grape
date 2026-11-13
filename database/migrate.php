<?php
/**
 * Migrações leves, idempotentes, executadas pelo bin/start-host.bat.
 *
 * Corrige bancos que já existiam antes de uma mudança de schema, sem exigir
 * que o usuário rode SQL na mão. Rodar de novo não faz nada.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use GamingCalls\Config\Config;
use GamingCalls\Database\Database;

Config::load(__DIR__ . '/../config/config.php');
Database::setConfig(Config::get('database'));

try {
    $db = Database::getInstance();
} catch (\Throwable $e) {
    fwrite(STDERR, "Migracoes: banco indisponivel - " . $e->getMessage() . PHP_EOL);
    exit(1);
}

// avatar_data era TEXT (64 KB) e nao cabia uma foto de perfil em data URL,
// o que derrubava o join na sala (SQLSTATE 22001 / 1406).
$stmt = $db->prepare(
    "SELECT DATA_TYPE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'room_participants' AND COLUMN_NAME = 'avatar_data'"
);
$stmt->execute();
$type = strtolower((string) $stmt->fetchColumn());

if ($type !== '') {
    if ($type !== 'mediumtext' && $type !== 'longtext') {
        $db->exec("ALTER TABLE `room_participants` MODIFY `avatar_data` MEDIUMTEXT NULL");
        echo "Migracao aplicada: room_participants.avatar_data ($type -> mediumtext)" . PHP_EOL;
    } else {
        echo "Migracoes: nada a fazer (avatar_data ja e $type)" . PHP_EOL;
    }
} else {
    echo "Migracoes: tabela room_participants ainda nao existe (rode database/schema.sql)" . PHP_EOL;
}
