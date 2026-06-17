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
