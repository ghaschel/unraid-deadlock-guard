<?php
declare(strict_types=1);
namespace DeadlockGuard;
use RuntimeException;
final class Config
{
    public static function member(mixed $m): array
    {
        if (!is_array($m) || !isset($m['type'], $m['id']) || !is_string($m['id'])) throw new RuntimeException('Invalid workload');
        $valid = match ($m['type']) {
            'vm' => preg_match('/^[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}$/D', $m['id']),
            'docker' => preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,254}$/D', $m['id']),
            default => false,
        };
        if (!$valid) throw new RuntimeException('Invalid workload identifier');
        return ['type'=>$m['type'], 'id'=>$m['type'] === 'vm' ? strtolower($m['id']) : $m['id']];
    }
    public static function key(array $m): string { return $m['type'].':'.$m['id']; }
    public static function validate(mixed $input): array
    {
        if (!is_array($input) || ($input['version'] ?? null) !== 1) throw new RuntimeException('Unsupported configuration version');
        if (!isset($input['groups']) || !is_array($input['groups']) || !array_is_list($input['groups']) || count($input['groups']) > 128) throw new RuntimeException('Invalid groups');
        $groups = []; $ids = [];
        foreach ($input['groups'] as $g) {
            if (!is_array($g) || !is_string($g['id'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $g['id'])) throw new RuntimeException('Invalid group ID');
            if (isset($ids[$g['id']])) throw new RuntimeException('Duplicate group ID');
            $ids[$g['id']] = true;
            if (!is_string($g['name'] ?? null) || trim($g['name']) === '' || strlen($g['name']) > 120 || preg_match('/[\x00-\x1f]/',$g['name'])) throw new RuntimeException('Invalid group name');
            if (!is_bool($g['enabled'] ?? null)) throw new RuntimeException('Invalid enabled flag');
            if (!is_array($g['members'] ?? null) || !array_is_list($g['members']) || count($g['members']) < 2 || count($g['members']) > 128) throw new RuntimeException('Invalid group members (need 2–128)');
            $members = [];
            foreach ($g['members'] as $m) {
                $m = self::member($m); $key = self::key($m);
                if (isset($members[$key])) throw new RuntimeException('Duplicate group member');
                $members[$key] = $m;
            }
            $result = ['id'=>$g['id'], 'name'=>trim($g['name']), 'enabled'=>$g['enabled'], 'members'=>array_values($members)];
            foreach (['vmTimeout'=>120, 'containerTimeout'=>30] as $field=>$default) {
                $value = $g[$field] ?? $default;
                if (!is_int($value) || $value < 1 || $value > 1800) throw new RuntimeException('Invalid shutdown timeout (1–1800 seconds)');
                $result[$field] = $value;
            }
            foreach (['forceVm','forceContainer'] as $field) {
                $value = $g[$field] ?? false;
                if (!is_bool($value)) throw new RuntimeException('Invalid force-stop flag');
                $result[$field] = $value;
            }
            $groups[] = $result;
        }
        return ['version'=>1, 'groups'=>$groups];
    }
    public static function groupsFor(array $config, array $m): array
    {
        return array_values(array_filter($config['groups'], static fn($g)=>$g['enabled'] && in_array($m,$g['members'],true)));
    }
    public static function requests(mixed $requests): array
    {
        if (!is_array($requests) || !array_is_list($requests) || !$requests || count($requests)>128) throw new RuntimeException('Invalid request list');
        $result=[];
        foreach ($requests as $r) {
            if (!is_array($r) || !in_array($r['action'] ?? null,['start','restart','resume','wake'],true)) throw new RuntimeException('Invalid action');
            $m=self::member($r['workload'] ?? null);
            if ($r['action']==='wake' && $m['type']!=='vm') throw new RuntimeException('Invalid wake action');
            $key=self::key($m);
            if (isset($result[$key])) throw new RuntimeException('Duplicate requested workload');
            $result[$key]=['workload'=>$m,'action'=>$r['action']];
        }
        ksort($result); return array_values($result);
    }
    public static function plan(array $config, array $requests): array
    {
        $requests=self::requests($requests);$groups=[];$conflicts=[];$all=[];
        foreach ($requests as $r) $all[self::key($r['workload'])]=$r['workload'];
        foreach ($config['groups'] as $g) {
            if (!$g['enabled']) continue;
            $targets=array_filter($g['members'],fn($m)=>isset($all[self::key($m)]));
            if (count($targets)>1) throw new RuntimeException('Choose one member of exclusive group: '.$g['name']);
            if (!$targets) continue;
            $groups[]=$g['id'];
            foreach ($g['members'] as $m) if (!isset($all[self::key($m)])) $conflicts[self::key($m)]=$m;
        }
        sort($groups); ksort($conflicts);
        return ['requests'=>$requests,'groups'=>$groups,'conflicts'=>array_values($conflicts)];
    }
    public static function policy(array $config, array $plan, array $m): array
    {
        $vm=$m['type']==='vm';$timeout=0;$force=true;
        foreach (self::groupsFor($config,$m) as $g) if(in_array($g['id'],$plan['groups'],true)) {
            $timeout=max($timeout,$g[$vm?'vmTimeout':'containerTimeout']);
            $force=$force && $g[$vm?'forceVm':'forceContainer'];
        }
        return ['timeout'=>$timeout ?: ($vm?120:30),'force'=>$timeout>0 && $force];
    }
}
