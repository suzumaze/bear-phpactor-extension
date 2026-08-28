<?php

declare(strict_types=1);

// Aura.Router goto demo: Cmd/Ctrl+B on '/index' or '/dashboard' jumps to the Page resource.
$map->get('index', '/index', '/index');
$map->get('dashboard', '/dashboard', '/dashboard');
$map->get('missing', '/missing', '/missing');
$map->get('escape', '/../../Client', '/../../Client');
