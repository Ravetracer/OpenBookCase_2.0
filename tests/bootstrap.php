<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (file_exists(dirname(__DIR__).'/config/bootstrap.php')) {
    require dirname(__DIR__).'/config/bootstrap.php';
} elseif (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// The OAuth2 keypair is gitignored (a secret), but the test kernel boots the
// resource-server firewall which needs it. Generate one on the fly if missing
// (fresh checkout / CI) so the suite is self-contained.
if (!file_exists(dirname(__DIR__).'/config/jwt/private.pem')) {
    passthru(sprintf(
        '%s %s/bin/console league:oauth2-server:generate-keypair --no-interaction --quiet',
        escapeshellarg(PHP_BINARY),
        dirname(__DIR__),
    ));
}

// Each TEST_TOKEN gets its own SQLite file (see .env.test), so parallel runs don't
// share a database. Build the schema for a fresh/empty file (schema-create, not
// migrations — this project's migrations can't build an empty DB).
$testDb = sprintf('%s/var/test%s.db', dirname(__DIR__), $_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN') ?: '');
if (!file_exists($testDb) || filesize($testDb) === 0) {
    passthru(sprintf(
        'APP_ENV=test %s %s/bin/console doctrine:schema:create --quiet',
        escapeshellarg(PHP_BINARY),
        dirname(__DIR__),
    ));
}
