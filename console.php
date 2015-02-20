#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';

use Wuelto\Console\Command\MigrateCommand;
use Symfony\Component\Console\Application;

$aplication = new Application();
$aplication->add(new MigrateCommand());
$aplication->run();