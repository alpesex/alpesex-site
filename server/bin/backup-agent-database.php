<?php

declare(strict_types=1);

use AlpesEx\Portal\Config;

// Run only on the server, in a pre-created mode-0700 backup directory.
// Credentials are loaded by the existing private bootstrap and never printed.
$destination = $argv[1] ?? '';
if ($destination === '' || !is_dir($destination) || !is_writable($destination)) {
    fwrite(STDERR, "Répertoire de sauvegarde invalide.\n");
    exit(1);
}
$appDirectory = getenv('ALPESEX_APP_DIR') ?: dirname(__DIR__);
/** @var array{config: Config} $services */
$services = require $appDirectory . '/bootstrap.php';
$config = $services['config'];
$tool = null;
foreach (['mariadb-dump', 'mysqldump'] as $candidate) {
    $paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));
    foreach ($paths as $path) {
        if (is_executable($path . DIRECTORY_SEPARATOR . $candidate)) {
            $tool = $path . DIRECTORY_SEPARATOR . $candidate;
            break 2;
        }
    }
}
if ($tool === null) {
    fwrite(STDERR, "Outil de sauvegarde MariaDB absent.\n");
    exit(1);
}
$optionFile = tempnam($destination, 'db-options-');
$output = $destination . '/database.sql';
if ($optionFile === false) {
    fwrite(STDERR, "Création des options impossible.\n");
    exit(1);
}
chmod($optionFile, 0600);
try {
    $escape = static function (string $value): string {
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new RuntimeException('Identifiant DB invalide pour le dump.');
        }
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    };
    $options = "[client]\n"
        . 'host="' . $escape($config->string('DB_HOST')) . "\"\n"
        . 'port="' . $config->integer('DB_PORT') . "\"\n"
        . 'user="' . $escape($config->string('DB_USERNAME')) . "\"\n"
        . 'password="' . $escape($config->string('DB_PASSWORD')) . "\"\n";
    if (file_put_contents($optionFile, $options, LOCK_EX) === false) {
        throw new RuntimeException('Options DB impossibles à écrire.');
    }
    $process = proc_open([
        $tool, '--defaults-extra-file=' . $optionFile, '--single-transaction', '--quick',
        '--result-file=' . $output, $config->string('DB_NAME'),
    ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Outil de sauvegarde impossible à lancer.');
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || !is_file($output) || filesize($output) < 100) {
        throw new RuntimeException('Sauvegarde DB incomplète.');
    }
    chmod($output, 0600);
    fwrite(STDOUT, "Sauvegarde DB créée et non affichée.\n");
} catch (Throwable) {
    @unlink($output);
    fwrite(STDERR, "Sauvegarde DB interrompue. Aucun fichier déployé.\n");
    exit(1);
} finally {
    @unlink($optionFile);
}
