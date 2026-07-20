<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// The Docker container injects a real MAILER_DSN (smtp://mailer:1025) as an
// environment variable. Real env vars win over .env files, so it would override
// MAILER_DSN=null://null from .env.test and the suite would deliver real emails
// to the dev Mailpit. Clear it here so the isolated null transport from
// .env.test takes effect. The test database is already isolated separately via
// dbname_suffix (config/packages/doctrine.yaml, when@test), so DATABASE_URL is
// intentionally left untouched.
putenv('MAILER_DSN');
unset($_ENV['MAILER_DSN'], $_SERVER['MAILER_DSN']);

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
