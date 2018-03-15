<?php
require __DIR__ . '/../vendor/autoload.php';
define('_PS_VERSION_', 'test');
define('_DB_PREFIX_', 'ps_');
define('_MYSQL_ENGINE_', 'InnoDB');

require __DIR__.'/mocks/Module.php';

require __DIR__ . '/../sizeid.php';
Tester\Environment::setup();
