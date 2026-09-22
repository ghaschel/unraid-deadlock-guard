/* Deadlock Guard native control adapter. Contracts: Unraid webgui 7.3. */
(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else { root.DeadlockGuard = api; api.boot(root); }
})(typeof window === 'undefined' ? globalThis : window, function () {
  'use strict';
  const vmActions = {'domain-start':'start','domain-start-console':'start','domain-start-consoleRV':'start','domain-restart':'restart','domain-resume':'resume','domain-pmwakeup':'wake'};
  const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
  const key = () => 'dg-' + (globalThis.crypto?.randomUUID?.() || Date.now().toString(36) + Math.random().toString(36).slice(2));
  async function request(body) {
    const csrf = window.DeadlockGuardToken || window.csrf_token || '';
    const response = await fetch('/plugins/deadlock-guard/include/api.php', {method:'POST', credentials:'same-origin', cache:'no-store', headers:{'Content-Type':'application/json','X-CSRF-Token':csrf}, body:JSON.stringify({...body,csrf})});
    const data = await response.json().catch(() => {throw Error('Unraid session or integration unavailable. Reload and sign in; an accepted handoff continues in the background.');});
    if (!response.ok || data.error) throw Error(data.error || 'Request failed');
    return data;
  }
  function install(win, send, report, openConsole) {
    const pending = win.__deadlockGuardPending ||= new Map();
    function wrap(name, translate) {
      const original=win[name];
      if (typeof original !== 'function' || original.deadlockGuard) return false;
      const wrapped=function (...args) {
        const native=translate(args[0]);
        if (!native) return original.apply(this,args);
        const identity=JSON.stringify(native);
        if (pending.has(identity)) return pending.get(identity);
        const mode=name==='ajaxVMDispatchconsoleRV'?'rv':name==='ajaxVMDispatchconsole'?'browser':null;
        // Open in the original click gesture so delayed handoffs can retain the console window.
        const popup=mode==='browser' && win.open ? win.open('about:blank','_blank') : null;
        const work=(async()=>{
          let accepted=null;
          try {
            report('Checking exclusive groups…');
            const result=await send({op:'route',native,key:key()});
            if (!result.managed) {popup?.close();report('');return original.apply(this,args);}
            let job=result.job;accepted=job.id;
            while (!['succeeded','failed','quarantined'].includes(job.status)) {
              report(job.phase + ' · ' + job.id);await delay(1000);
              job=(await send({op:'status',id:job.id})).job;
            }
            if (job.status!=='succeeded') throw Error(job.error || job.phase);
            report(job.phase || 'Handoff complete');
            if (mode) await openConsole(result.requests[0].workload,mode,popup);
            if (typeof win[args[1]]==='function') win[args[1]]();else if(typeof win.loadlist==='function')win.loadlist();
          } catch(error) {popup?.close();report('Deadlock Guard: '+error.message+(accepted?' · Job '+accepted+' — see Settings → Deadlock Guard.':''));}
        })();
        pending.set(identity,work);work.finally(()=>pending.delete(identity));return work;
      };
      wrapped.deadlockGuard=true;win[name]=wrapped;return true;
    }
    wrap('eventControl',p=>p && ['start','restart','resume'].includes(p.action)?{type:'docker',id:p.container,action:p.action}:null);
    for(const name of ['ajaxVMDispatch','ajaxVMDispatchconsole','ajaxVMDispatchconsoleRV','ajaxVMDispatchWebUI'])wrap(name,p=>p && vmActions[p.action]?{type:'vm',id:p.uuid,action:vmActions[p.action]}:null);
    const page=win.location.pathname;
    const type=/^\/(Docker)(\/|$)/i.test(page)?'docker':/^\/(VMs|VM)(\/|$)/i.test(page)?'vm':null;
    if(type)wrap('startAll',()=>({type,bulk:true,action:'start'}));
    if(type==='docker')wrap('resumeAll',()=>({type,bulk:true,action:'resume'}));
    return {docker:!!win.eventControl?.deadlockGuard,vm:!!win.ajaxVMDispatch?.deadlockGuard};
  }
  function report(message) {
    let node=document.getElementById('dg-progress');
    if(!node){node=document.createElement('div');node.id='dg-progress';node.setAttribute('role','status');node.setAttribute('aria-live','polite');document.body.append(node);}
    node.textContent=message;node.hidden=!message;
  }
  async function openConsole(workload,mode,popup) {
    const data=await request({op:'console',workload});
    if(mode==='rv') {
      const blob=new Blob(['[virt-viewer]\ntype='+data.protocol+'\nhost='+location.hostname+'\nport='+data.port+'\ndelete-this-file=1\n'],{type:'application/x-virt-viewer'});
      const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='deadlock-guard.vv';a.click();setTimeout(()=>URL.revokeObjectURL(url),30000);return;
    }
    const url=new URL('/plugins/dynamix.vm.manager/'+data.protocol+'.html',location.origin);
    url.searchParams.set('autoconnect','true');url.searchParams.set('host',location.host);
    if(data.protocol==='vnc'){url.searchParams.set('port','');url.searchParams.set('path','/wsproxy/'+data.websocket+'/');url.searchParams.set('resize','scale');}
    else{url.searchParams.set('port','/wsproxy/'+data.port+'/');url.searchParams.set('vmname',data.name || workload.id);}
    if(popup && !popup.closed)popup.location.replace(url.href);
    else {const a=document.createElement('a');a.href=url.href;a.target='_blank';a.rel='noopener';a.textContent='Open VM console';document.getElementById('dg-progress').append(' · ',a);}
  }
  function boot(win) {
    const setup=()=>{
      const coverage=install(win,request,report,openConsole);
      if((/^\/Docker(?:\/|$)/i.test(location.pathname) && !coverage.docker) || (/^\/VMs(?:\/|$)/i.test(location.pathname) && !coverage.vm))report('Deadlock Guard integration is unavailable on this page. Reload before starting grouped workloads.');
      // Unraid can refresh page fragments. Re-wrap newly installed dispatchers idempotently.
      setInterval(()=>install(win,request,report,openConsole),1000);
    };
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',setup,{once:true});else setup();
  }
  return {install,request,report,boot};
});
