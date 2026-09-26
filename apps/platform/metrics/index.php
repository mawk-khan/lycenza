<?php

/*
 * Phase 0O.5A (ADR 0051 §9): the private metrics listener's front
 * controller. Deliberately OUTSIDE public/ and NOT a Laravel route: the
 * production image's nginx maps it only on the internal port (9102), with
 * FastCGI parameter LYCENZA_METRICS_LISTENER=1. The public listener only
 * ever executes public/index.php, whose router has no metrics route.
 *
 * Boots the application (configuration, providers -- including the
 * production configuration guard) without the HTTP middleware stack:
 * metrics stay available during a maintenance window for diagnostics,
 * and a scrape never opens a session.
 */

use App\Support\Observability\Metrics\MetricsEndpoint;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$request = Request::capture();
$app->instance('request', $request);

$app->make(MetricsEndpoint::class)->respond($request)->send();
