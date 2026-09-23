<?php
// Render the real plugin page entrypoints in the browser fixture, including script tags.
$docroot = dirname(__DIR__) . '/src/usr/local/emhttp';
function pageBody(string $name): string
{
    global $docroot;
    return explode("---\n", file_get_contents("$docroot/plugins/deadlock-guard/$name.page"), 2)[1];
}
echo '<!doctype html><html><head><meta charset="utf-8"><title>Deadlock Guard settings fixture</title><style>body{font:14px Arial;background:#202020;color:#ddd;margin:24px}button{padding:8px 12px}</style>';
eval('?>' . pageBody('DeadlockGuardButtons'));
echo '<script>window.DeadlockGuardToken="fixture";</script></head><body>';
eval('?>' . pageBody('DeadlockGuard'));
echo '</body></html>';
