(function(){'use strict';
  const start=()=>{
    const root=document.getElementById('deadlock-guard');if(!root)return;
    const api=window.DeadlockGuard;let snapshot=null;
    const el=(tag,text,props={})=>Object.assign(document.createElement(tag),{textContent:text,...props});
    const byId=id=>document.getElementById(id);
    const message=text=>{byId('dg-message').textContent=text;};
    const key=m=>m.type+':'+m.id;
    function field(parent,title,input){const label=el('label','');label.append(el('span',title),input);parent.append(label);return input;}
    function groupEditor(group){
      const card=el('fieldset','',{className:'dg-group'});card.dataset.id=group.id;
      const name=field(card,'Group name',el('input','',{type:'text',value:group.name,maxLength:120}));name.dataset.field='name';
      const enabled=field(card,'Enabled',el('input','',{type:'checkbox',checked:group.enabled}));enabled.dataset.field='enabled';
      const list=el('div','',{className:'dg-members'});card.append(el('p','Select at least two workloads. A workload may belong to several groups.'),list);
      const all=[...snapshot.inventory.workloads];for(const m of group.members)if(!all.some(x=>key(x)===key(m)))all.push({...m,name:m.id,status:'missing'});
      for(const m of all){const box=el('input','',{type:'checkbox',checked:group.members.some(x=>key(x)===key(m))});box.dataset.member=JSON.stringify({type:m.type,id:m.id});field(list,m.type.toUpperCase()+' · '+(m.name||m.id)+' · '+m.status,box);}
      for(const [label,prop,defaultValue] of [['VM timeout (seconds)','vmTimeout',120],['Container timeout (seconds)','containerTimeout',30]]){const input=field(card,label,el('input','',{type:'number',min:1,max:1800,value:group[prop]??defaultValue}));input.dataset.field=prop;}
      for(const [label,prop] of [['Allow force-stop for VMs after timeout','forceVm'],['Allow force-stop for containers after timeout','forceContainer']]){const box=field(card,label,el('input','',{type:'checkbox',checked:!!group[prop]}));box.dataset.field=prop;}
      const remove=el('button','Remove group',{type:'button'});remove.onclick=()=>card.remove();card.append(remove);byId('dg-groups').append(card);
    }
    function history(jobs){const node=byId('dg-history');node.replaceChildren();if(!jobs.length)node.append(el('p','No handoffs yet.'));
      for(const job of jobs.slice(0,30)){const details=el('details','');details.append(el('summary',new Date(job.createdAt*1000).toLocaleString()+' · '+job.status+' · '+job.phase));const lines=[job.id,job.error||'',...Object.entries(job.states||{}).map(([id,s])=>id+': '+s.status),...(job.history||[]).map(h=>new Date(h.at*1000).toLocaleTimeString()+' '+h.message)];details.append(el('pre',lines.filter(Boolean).join('\n')));node.append(details);}
    }
    function inventory(data){const table=el('table',''),head=el('tr','');for(const x of ['Workload','State','Configuration warnings'])head.append(el('th',x));table.append(head);
      for(const m of data.workloads){const warnings=[];if(m.autostart)warnings.push('Autostart enabled');if(m.restartPolicy && m.restartPolicy!=='no')warnings.push('Docker restart policy: '+m.restartPolicy);if(m.error)warnings.push(m.error);const row=el('tr','');for(const text of [m.type.toUpperCase()+' · '+m.name,m.status,warnings.join('; ')])row.append(el('td',text));table.append(row);}byId('dg-inventory').replaceChildren(table);
      for(const [type,error] of Object.entries(data.errors))byId('dg-inventory').append(el('p',type+': '+error));
      for(const g of snapshot.config.groups)for(const m of g.members)if(!data.workloads.some(x=>key(x)===key(m)))byId('dg-inventory').append(el('p','Repair missing member in '+g.name+': '+key(m)));
    }
    async function reload(){snapshot=await api.request({op:'snapshot'});byId('dg-health').textContent=snapshot.health.message;byId('dg-groups').replaceChildren();snapshot.config.groups.forEach(groupEditor);inventory(snapshot.inventory);history(snapshot.jobs);message('Configuration loaded.');}
    const safe=fn=>async()=>{try{await fn();}catch(e){message(e.message);}};
    byId('dg-reload').onclick=safe(reload);
    byId('dg-add').onclick=()=>{if(snapshot)groupEditor({id:'g-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2,7),name:'New group',enabled:true,members:[]});};
    byId('dg-save').onclick=safe(async()=>{if(!snapshot)return;const groups=Array.from(root.querySelectorAll('.dg-group')).map(card=>{const g={id:card.dataset.id,members:Array.from(card.querySelectorAll('[data-member]:checked')).map(x=>JSON.parse(x.dataset.member))};for(const i of card.querySelectorAll('[data-field]'))g[i.dataset.field]=i.type==='checkbox'?i.checked:i.type==='number'?Number(i.value):i.value;return g;});const r=await api.request({op:'config',config:{version:1,groups},revision:snapshot.revision});snapshot.config=r.config;snapshot.revision=r.revision;message('Groups saved. Reload other open Unraid tabs after installing or upgrading the plugin.');inventory(snapshot.inventory);});
    byId('dg-check').onclick=safe(async()=>{const r=await api.request({op:'reconcile'});byId('dg-health').textContent=r.health.message;message('Integration checked; uncertain jobs remain reserved.');history((await api.request({op:'history'})).jobs);});
    safe(reload)();setInterval(()=>{if(!document.hidden)api.request({op:'history'}).then(r=>history(r.jobs)).catch(e=>message(e.message));},3000);
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
