<?php
use DeadlockGuard\Config;

function member(string $type, string $id): array
{
    return ['type' => $type, 'id' => $id];
}
function group(string $id, array $members, array $extra = []): array
{
    return array_merge(
        ['id' => $id, 'name' => $id, 'enabled' => true, 'members' => $members],
        $extra,
    );
}
function config(array $groups): array
{
    return Config::validate(['version' => 1, 'groups' => $groups]);
}
