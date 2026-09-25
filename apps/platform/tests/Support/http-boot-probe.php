<?php

// ProductionBootSmokeTest: serves ONE GET (default /login; PROBE_URI
// overrides it) through public/index.php in this separate process, the way
// a web server would, so the test can prove an unsafe production
// configuration never serves a page.

$_SERVER['REQUEST_URI'] = getenv('PROBE_URI') ?: '/login';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/index.php';

chdir(__DIR__.'/../../public');

require __DIR__.'/../../public/index.php';

echo PHP_EOL.'SERVED:'.http_response_code();
