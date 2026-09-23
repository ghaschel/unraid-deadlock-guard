<?php
// Keep Unraid's custom-network host routes after a coordinated start.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/lib/bootstrap.php';
$member = DeadlockGuard\Config::member(['type' => 'docker', 'id' => $argv[1] ?? '']);
$docroot = '/usr/local/emhttp';
require_once $docroot . '/plugins/dynamix.docker.manager/include/DockerClient.php';
addRoute($member['id']);
