<?php

$map->route('/listing', '/latest');
$map->get('/listing', '/entries');
$map->post('/create', '/entries');
$map->route('/alias', '/custom', '/listing');
$map->get(name: '/listing', path: '/named');
$map->get('/listing', '/item/{id}')->tokens(['id' => '\\d+']);
$map->get('/listing', $dynamicPath);
if ($enabled) {
    $map->get('/listing', '/conditional');
}
