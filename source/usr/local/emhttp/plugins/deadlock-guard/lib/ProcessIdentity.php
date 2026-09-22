<?php
declare(strict_types=1);
namespace DeadlockGuard;
final class ProcessIdentity
{
    public static function of(int $pid): ?string
    {
        if($pid<1)return null;
        $stat=@file_get_contents('/proc/'.$pid.'/stat');
        if($stat===false)return null;
        $end=strrpos($stat,')');if($end===false)return null;
        $fields=preg_split('/\s+/',trim(substr($stat,$end+1)));
        if(!isset($fields[19]) || $fields[0]==='Z')return null;
        return trim((string)@file_get_contents('/proc/sys/kernel/random/boot_id')).':'.$pid.':'.$fields[19];
    }
    public static function alive(?array $record): bool
    {
        if(!$record || !is_int($record['pid']??null) || !is_string($record['processIdentity']??null))return false;
        return self::of($record['pid'])===$record['processIdentity'];
    }
}
