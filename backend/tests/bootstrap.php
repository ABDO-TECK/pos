<?php

// Tests use isolated in-process databases and never expose an HTTP listener.
putenv('DEPLOYMENT_MODE=desktop');
$_ENV['DEPLOYMENT_MODE'] = 'desktop';
putenv('APP_ENV=development');
$_ENV['APP_ENV'] = 'development';

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../Config/config.php';

// Testing environment is initialized
define('PHPUNIT_TEST_SUITE', true);
