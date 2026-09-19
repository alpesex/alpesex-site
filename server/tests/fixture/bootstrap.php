<?php
declare(strict_types=1);
// Used only by the disposable integration-test server; no application deployment points here.
if (getenv('DB_NAME') !== 'cpmp_test_mobile') throw new RuntimeException('Integration test database required');
spl_autoload_register(static function (string $class): void {
    $prefix = 'AlpesEx\\Portal\\';
    if (str_starts_with($class, $prefix)) require dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
});
$_ENV['ALPESEX_APPLICATION_KEY'] = str_repeat('ab', 32);
return ['config' => new AlpesEx\Portal\Config([
    'DB_HOST'=>getenv('DB_HOST'), 'DB_PORT'=>getenv('DB_PORT'), 'DB_NAME'=>getenv('DB_NAME'),
    'DB_USERNAME'=>getenv('DB_USERNAME'), 'DB_PASSWORD'=>getenv('DB_PASSWORD'),
])];
