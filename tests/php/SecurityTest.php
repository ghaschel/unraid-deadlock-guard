<?php
use DeadlockGuard\Security;
test('API requires native authenticated root session and its own CSRF proof', function () {
    raises(
        fn() => Security::authorize(
            [],
            ['REQUEST_METHOD' => 'POST'],
            ['csrf' => 'secret'],
            'secret',
        ),
        'authenticated',
    );
    raises(
        fn() => Security::authorize(
            ['unraid_login' => time(), 'unraid_user' => 'root'],
            ['REQUEST_METHOD' => 'POST'],
            ['csrf' => 'wrong'],
            'secret',
        ),
        'CSRF',
    );
    raises(
        fn() => Security::authorize(
            ['unraid_login' => time(), 'unraid_user' => 'root'],
            ['REQUEST_METHOD' => 'GET'],
            ['csrf' => 'secret'],
            'secret',
        ),
        'POST',
    );
    Security::authorize(
        ['unraid_login' => time(), 'unraid_user' => 'root'],
        ['REQUEST_METHOD' => 'POST'],
        ['csrf' => 'secret'],
        'secret',
    );
});
