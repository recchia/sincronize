#!/usr/bin/env php
<?php
require __DIR__ . '/vendor/autoload.php';

use Wuelto\Console\Command\MigrateCommand;
use Wuelto\Console\Command\FixCommand;
use Symfony\Component\Console\Application;

$aplication = new Application();
$aplication->add(new MigrateCommand());
$aplication->add(new FixCommand());
$aplication->run();