<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/../src/routes.php';

use Qlip\App\App;
use Qlip\App\Request;

$app = new App();

$request = Request::fromGlobals($_SERVER, $_GET, $_POST);

qlip_routes($app)->dispatch($request)->send();
