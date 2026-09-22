<?php
declare(strict_types=1);
namespace DeadlockGuard;
use RuntimeException;
final class Runner implements CommandRunner
{
    public function run(array $argv,float $timeout=10,?string $input=null,bool $mutation=false): string
    {
        if(!$argv || $timeout<=0)throw new RuntimeException('Invalid command');
        foreach($argv as $arg)if(!is_string($arg) || str_contains($arg,"\0"))throw new RuntimeException('Invalid command argument');
        $env=['PATH'=>'/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin','LC_ALL'=>'C','LANG'=>'C'];
        $process=proc_open($argv,[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,null,$env,['bypass_shell'=>true]);
        if(!is_resource($process))throw new RuntimeException('Could not start command');
        if($input!==null)fwrite($pipes[0],$input);fclose($pipes[0]);
        stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        $out='';$err='';$deadline=hrtime(true)/1e9+$timeout;$status=null;
        try{
            while(true){
                $out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);
                if(strlen($out)+strlen($err)>4*1024*1024)throw ($mutation ? new UncertainOperation('Command output exceeds limit; operation uncertain') : new RuntimeException('Command output exceeds limit'));
                $status=proc_get_status($process);if(!$status['running'])break;
                if(hrtime(true)/1e9>=$deadline){
                    $message=basename($argv[0]).' timed out; '.($mutation?'operation outcome is uncertain':'service is unavailable');
                    if($mutation)throw new UncertainOperation($message);throw new RuntimeException($message);
                }
                usleep(20000);
            }
            $out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);
            if($status['exitcode']!==0){$message=basename($argv[0]).': '.trim(substr($err ?: $out,0,2000));if($mutation)throw new UncertainOperation($message.'; operation outcome is uncertain');throw new RuntimeException($message);}
            return $out;
        }finally{
            if(($status['running']??true)){proc_terminate($process,15);usleep(50000);$s=proc_get_status($process);if($s['running'])proc_terminate($process,9);}
            fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
        }
    }
}
