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
// Same for the court filing transport and the inbound mailbox: a value injected by
// the container must never let the suite send to, or read from, a real server.
foreach (['COURT_FILING_MAILER_DSN', 'INBOUND_MAIL_DSN', 'COURT_FILING_LIVE', 'COURT_FILING_RECIPIENT_OVERRIDE'] as $name) {
    putenv($name);
    unset($_ENV[$name], $_SERVER[$name]);
}

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// Documents the suite stores land in var/uploads_test (app.uploads_dir, when@test).
// Start every run from an empty directory so they never pile up between runs.
(new Symfony\Component\Filesystem\Filesystem())->remove(dirname(__DIR__).'/var/uploads_test');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
