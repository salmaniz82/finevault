const {chromium}=require('C:/Users/lenovo/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs=require('fs'); const path=require('path');
const out=path.resolve(__dirname,'../../output/workflow-review'); fs.mkdirSync(out,{recursive:true});
(async()=>{
 const browser=await chromium.launch({headless:true}); const errors=[];const results=[];
 try{
 for(const role of ['admin','manager','client']){
  const context=await browser.newContext({viewport:{width:1440,height:1000}});const page=await context.newPage();
  page.on('pageerror',e=>errors.push(role+': '+e.message));
  await page.goto('http://127.0.0.1:8020/login');await page.locator('[name=email]').fill(role+'@fineworth.test');await page.locator('[name=password]').fill('FineworthDemo!27');
  await Promise.all([page.waitForURL(u=>!u.pathname.startsWith('/login')),page.locator('button[type=submit]').click()]);
  const paths=role==='admin'?['/dashboard','/clients','/holdings','/deals','/requests','/activity']:role==='manager'?['/dashboard','/clients','/requests']:['/onboarding'];
  for(const route of paths){
   const response=await page.goto('http://127.0.0.1:8020'+route);await page.screenshot({path:path.join(out,role+'-'+route.slice(1)+'.png'),fullPage:true});
   results.push({role,route,status:response.status(),title:await page.locator('h1').first().innerText(),overflow:await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1)});
   if(route==='/requests' && role==='admin') {const link=page.locator('a[href^="/requests/"]').first();if(await link.count()){const href=await link.getAttribute('href');const r=await page.goto('http://127.0.0.1:8020'+href);await page.screenshot({path:path.join(out,'admin-request-detail.png'),fullPage:true});results.push({role,route:href,status:r.status()});}}
   if(route==='/clients') {const link=page.locator('a[href^="/client/"]').first();if(await link.count()){const href=await link.getAttribute('href');let r=await page.goto('http://127.0.0.1:8020'+href);results.push({role,route:href,status:r.status()});r=await page.goto('http://127.0.0.1:8020/clients/'+href.split('/').pop()+'/onboarding');results.push({role,onboardingDetail:r.status()});}}
   if(route==='/deals' && role==='admin') {await page.locator('[data-add-item]').click();await page.locator('[data-item-type]').last().selectOption('wine');results.push({mixedFormItems:await page.locator('[data-item]').count()});const link=page.locator('a[href^="/deals/"]').first();if(await link.count()){const r=await page.goto('http://127.0.0.1:8020'+await link.getAttribute('href'));results.push({role,agreementDetail:r.status()});}}
  }
  await page.setViewportSize({width:390,height:844});await page.goto('http://127.0.0.1:8020'+(role==='client'?'/onboarding':'/dashboard'));await page.screenshot({path:path.join(out,role+'-mobile.png'),fullPage:true});
  results.push({role,mobileOverflow:await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth+1)});
  await context.close();
 }
 fs.writeFileSync(path.join(out,'results.json'),JSON.stringify({results,errors},null,2));console.log(JSON.stringify({results,errors}));
 }finally{await browser.close();}
})();
