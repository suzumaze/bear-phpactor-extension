<?php

$map->route('/listing', '/latest');
$map->route('/listing', '/sitemap.xml');
$map->route('/listing', '/entry/{id}')->tokens(['id' => '\\d+']);
