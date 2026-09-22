<?php
declare(strict_types=1);
namespace DeadlockGuard;
use RuntimeException;
final class Router
{
    public function __construct(private array $config,private array $inventory){}
    public function route(array $input): array
    {
        $type=$input['type']??'';$action=$input['action']??'';
        if(!in_array($type,['vm','docker'],true) || !in_array($action,['start','restart','resume','wake'],true) || ($type==='docker' && $action==='wake'))throw new RuntimeException('Invalid native action');
        if(isset($this->inventory['errors'][$type]))throw new RuntimeException($this->inventory['errors'][$type]);
        $items=array_values(array_filter($this->inventory['workloads'],fn($x)=>$x['type']===$type));$requests=[];
        if(($input['bulk']??false)===true){
            if(!in_array($action,['start','resume'],true))throw new RuntimeException('Invalid bulk action');
            foreach($items as $m){
                if($action==='start' && $m['status']==='running')continue;
                if($action==='resume' && $m['status']!=='paused')continue;
                $requests[]=['workload'=>Config::member($m),'action'=>$action];
            }
        }else{
            $id=$input['id']??null;if(!is_string($id) || strlen($id)>255)throw new RuntimeException('Invalid workload reference');
            $matches=array_values(array_filter($items,fn($m)=>$type==='vm'?$m['id']===strtolower($id):($m['id']===$id || (preg_match('/^[a-f0-9]{12,64}$/D',$id) && str_starts_with($m['runtimeId']??'',$id)))));
            if(count($matches)!==1)throw new RuntimeException('Missing or ambiguous workload; refresh the page and repair its group if renamed');
            $requests[]=['workload'=>Config::member($matches[0]),'action'=>$action];
        }
        $managed=false;foreach($requests as $r)if(Config::groupsFor($this->config,$r['workload']))$managed=true;
        if($managed)Config::plan($this->config,Config::requests($requests));
        return ['managed'=>$managed,'requests'=>$requests];
    }
}
