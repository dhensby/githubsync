#!/usr/bin/env php
<?php

error_reporting(E_ALL);

foreach(array(
    'vendor/autoload.php',
    '../vendor/autoload.php',
    '../../vendor/autoload.php',
) as $dir) {
    if (file_exists($dir)) {
        require_once $dir;
    }
}

$application = new \Dhensby\GitHubSync\Console\Application();

$application->run();
