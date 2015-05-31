<?php
use Cake\Routing\Router;

Router::plugin('RateLimiter', function ($routes) {
    $routes->fallbacks('InflectedRoute');
});
