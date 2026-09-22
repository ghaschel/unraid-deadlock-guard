const {chromium}=require('playwright');const fs=require('node:fs');const path=require('node:path');const assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch(process.env.DG_CHROME?{executablePath:process.env.DG_CHROME}:{});const page=await browser.newPage({viewport:{width:1280,height:1000}});const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const a={type:'vm',id:'11111111-1111-1111-1111-111111111111'},b={type:'docker',id:'FileFlows'};let config={version:1,groups:[{id:'gpu',name:'Shared GPU <img src=x onerror=alert(1)>',enabled:true,members:[a,b],vmTimeout:120,containerTimeout:30,forceVm:false,forceContainer:false}]};let polls=0;
 await page.route('http://fixture/**',async route=>{
  const url=new URL(route.request().url());
  if(url.pathname.endsWith('/api.php')){const req=route.request().postDataJSON();let data={};assert.equal(req.csrf,'fixture');
   if(req.op==='snapshot')data={config,revision:'rev',health:{ready:true,message:'VM safety gate installed and activated'},inventory:{workloads:[{...a,name:'Windows',status:'running',autostart:false},{...b,name:'FileFlows',status:'stopped',restartPolicy:'always'}],errors:{}},jobs:[]};
   if(req.op==='config'){config=req.config;data={config,revision:'next'};}
   if(req.op==='history')data={jobs:[]};
   if(req.op==='route')data={managed:true,requests:[{workload:b,action:'start'}],job:{id:'fixture-job',status:'running',phase:'Stopping Windows'}};
   if(req.op==='status')data={job:{id:'fixture-job',status:++polls>1?'succeeded':'running',phase:polls>1?'Handoff complete':'Waiting for resource release'}};
   return route.fulfill({json:data});
  }
  let file=url.pathname==='/Docker'||url.pathname==='/Dashboard'||url.pathname==='/VMs'? 'tests/fixtures/unraid-7.3.html':url.pathname.slice(1);
  return route.fulfill({path:path.resolve(file)});
 });
 for(const tab of ['/Docker','/Dashboard','/VMs']){await page.goto('http://fixture'+tab);await page.locator('#start-container').click();await page.locator('#dg-progress').filter({hasText:'Handoff complete'}).waitFor();assert.deepEqual(await page.evaluate(()=>nativeCalls),[]);}
 const markup=fs.readFileSync('source/usr/local/emhttp/plugins/deadlock-guard/DeadlockGuard.page','utf8').split('---\n')[1].replace(/<script[^>]*><\/script>/g,'');await page.locator('#settings-fixture').evaluate((node,html)=>node.innerHTML=html,markup);
 await page.addScriptTag({path:'source/usr/local/emhttp/plugins/deadlock-guard/javascript/settings.js'});await page.locator('#dg-message').filter({hasText:'Configuration loaded'}).waitFor();assert.equal(await page.locator('.dg-group img').count(),0);await page.locator('[data-field="name"]').fill('Shared GPU');await page.locator('#dg-save').click();await page.locator('#dg-message').filter({hasText:'Groups saved'}).waitFor();assert.equal(config.groups[0].name,'Shared GPU');await page.screenshot({path:'dist/settings-fixture.png',fullPage:true});assert.deepEqual(errors,[]);await browser.close();console.log('PASS browser fixtures: Docker, VMs, Dashboard, settings save and HTML escaping');
})().catch(e=>{console.error(e);process.exit(1)});
