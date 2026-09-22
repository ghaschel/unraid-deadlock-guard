<?php
declare(strict_types=1);
namespace DeadlockGuard;
use RuntimeException;
final class Security
{
    public static function authorize(array $session,array $server,array $body,string $expected): void
    {
        if(empty($session['unraid_login']) || ($session['unraid_user']??'')!=='root')throw new RuntimeException('An authenticated Unraid root session is required',401);
        if(($server['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('Use POST for this endpoint',405);
        if($expected==='' || !is_string($body['csrf']??null) || !hash_equals($expected,$body['csrf']))throw new RuntimeException('Invalid CSRF token',403);
    }
    public static function request(): array
    {
        if(($_SERVER['CONTENT_LENGTH']??0)>262144)throw new RuntimeException('Request too large',413);
        $raw=file_get_contents('php://input',false,null,0,262145);
        if($raw===false || strlen($raw)>262144)throw new RuntimeException('Request too large',413);
        $body=json_decode($raw,true,64,JSON_THROW_ON_ERROR);if(!is_array($body))throw new RuntimeException('Invalid JSON object',400);
        // The native auto_prepend_file selects Unraid's cookie name and validates X-CSRF-Token.
        // Check our JSON token too: local_prepend.php unsets the validated header before this runs.
        if(isset($_COOKIE[session_name()]) && session_status()===PHP_SESSION_NONE)session_start();
        $session=$_SESSION??[];if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        $var=@parse_ini_file('/var/local/emhttp/var.ini')?:[];
        self::authorize($session,$_SERVER,$body,(string)($var['csrf_token']??''));return $body;
    }
}
