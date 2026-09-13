<?php

declare(strict_types=1);

$map->route('/article', '/articles/{id}');
$map->get(name: '/article', path: '/article-alias');
$map->route('/x', '/ambiguous');
