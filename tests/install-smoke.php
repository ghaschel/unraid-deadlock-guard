<?php
// Linux container only. Emulates Slackware package extraction/removal, not an Unraid host.
if(PHP_SAPI!=='cli' || !is_file('/.dockerenv'))exit(1);
$base='/usr/local/emhttp/plugins/deadlock-guard';$flash='/boot/config/plugins/deadlock-guard';
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
function execute(array $argv):void{$p=proc_open($argv,[STDIN,STDOUT,STDERR],$pipes);if(proc_close($p)!==0)throw new RuntimeException('Command failed');}
@mkdir('/usr/local/sbin',0755,true);@mkdir('/usr/bin',0755,true);if(!is_file('/usr/bin/php'))symlink(PHP_BINARY,'/usr/bin/php');
file_put_contents('/etc/unraid-version',"version=\"7.3.0\"\n");
@mkdir('/etc/libvirt/hooks/qemu.d',0755,true);file_put_contents('/etc/libvirt/hooks/qemu.d/other','foreign hook');
file_put_contents('/usr/local/sbin/update_cron',"#!/bin/bash\nexit 0\n");chmod('/usr/local/sbin/update_cron',0755);
file_put_contents('/usr/local/sbin/upgradepkg',"#!/bin/bash\nset -e\narchive=\"\${@: -1}\"\ntar -xJf \"\$archive\" -C /\nbash /install/doinst.sh\n");chmod('/usr/local/sbin/upgradepkg',0755);
file_put_contents('/usr/local/sbin/removepkg',"#!/bin/bash\nrm -rf /usr/local/emhttp/plugins/deadlock-guard\n");chmod('/usr/local/sbin/removepkg',0755);
$xml=simplexml_load_file('/app/dist/deadlock-guard.plg');
foreach($xml->FILE as $file)if(isset($file->URL)){$path=(string)$file['Name'];@mkdir(dirname($path),0700,true);copy('/app/dist/'.basename($path),$path);check(hash_file('sha256',$path)===(string)$file->SHA256,'Package checksum mismatch');}
$run=function(string $method)use($xml){foreach($xml->FILE as $file){if(((string)$file['Method']?:'install')!==$method || !isset($file['Run']))continue;$script='/tmp/dg-manifest-step.sh';file_put_contents($script,(string)$file->INLINE);execute(['/bin/bash','-n',$script]);execute(['/bin/bash',$script]);}};
$run('install');check(is_file($base.'/lib/Coordinator.php'),'Install missing payload');check(is_executable('/etc/libvirt/hooks/qemu.d/99-deadlock-guard'),'Missing executable hook');check(is_file($flash.'/deadlock-guard.cron'),'Missing cron');
// Exercise the actual executable hook without a daemon or recursive libvirt calls.
require $base.'/lib/bootstrap.php';
$store=DeadlockGuard\Store::system();$vm=['type'=>'vm','id'=>'11111111-1111-1111-1111-111111111111'];$ct=['type'=>'docker','id'=>'disposable'];
$store->saveConfig(['version'=>1,'groups'=>[['id'=>'test','name'=>'Test','enabled'=>true,'members'=>[$vm,$ct]]]]);
$hook=function(string $operation,?string $uuid=null)use($vm){$p=proc_open(['/etc/libvirt/hooks/qemu.d/99-deadlock-guard','Ignored Name',$operation,$operation==='release'?'end':'begin','-'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);fwrite($pipes[0],'<domain><uuid>'.($uuid??$vm['id']).'</uuid></domain>');fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);return proc_close($p);};
check($hook('prepare')!==0,'Managed VM hook admitted unapproved start');
$job=(new DeadlockGuard\Jobs($store))->submit([['workload'=>$vm,'action'=>'start']],'hook-smoke');$store->updateJob($job['id'],['status'=>'running','pid'=>getmypid(),'processIdentity'=>DeadlockGuard\ProcessIdentity::of(getmypid())]);
(new DeadlockGuard\Gate($store))->issue($vm,$job['id']);check($hook('prepare')===0,'Authorized hook rejected');check($hook('prepare')!==0,'Hook reused authorization');check($hook('release')===0,'Release hook failed');
check(DeadlockGuard\Store::read($store->eventPath($vm))['phase']==='release','Missing release event');$store->updateJob($job['id'],['status'=>'succeeded']);
// Persistent hooks must protect grouped VMs yet leave other VMs usable if boot install fails.
rename($base,$base.'-offline');check($hook('prepare')!==0,'Missing payload admitted grouped VM');check($hook('prepare','22222222-2222-2222-2222-222222222222')===0,'Missing payload blocked ungrouped VM');rename($base.'-offline',$base);
file_put_contents('/etc/unraid-version',"version=\"7.4.0\"\n");check($hook('prepare')!==0,'Unsupported boot admitted grouped VM');check($hook('prepare','22222222-2222-2222-2222-222222222222')===0,'Unsupported boot blocked ungrouped VM');file_put_contents('/etc/unraid-version',"version=\"7.3.0\"\n");
$config=file_get_contents($flash.'/config.json');$run('install');check(file_get_contents($flash.'/config.json')===$config,'Upgrade replaced configuration');
$run('remove');check(!is_dir($base),'Removal left payload');check(file_get_contents($flash.'/config.json')===$config,'Removal lost configuration');check(file_get_contents('/etc/libvirt/hooks/qemu.d/other')==='foreign hook','Foreign hook modified');check(!is_file($flash.'/installed.json'),'Stale mounted-image hook still enabled');check(!is_file($flash.'/deadlock-guard.cron'),'Cron retained after removal');echo "PASS disposable Linux manifest install/update/remove and foreign-hook/config preservation\n";
