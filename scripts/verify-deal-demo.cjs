// Local fictional-data walkthrough. Does not reset the database or contact DocuSign.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/lenovo/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '../..');
const out = path.join(root, 'docs/screenshots');
const pdfOut = path.join(root, 'output/pdf');
fs.mkdirSync(out, {recursive:true}); fs.mkdirSync(pdfOut, {recursive:true});
(async () => {
 const browser = await chromium.launch({headless:true});
 let page;
 const pages=new Map(); const errors=[];
 const goto = p => page.goto('http://127.0.0.1:8000'+p);
 async function login(email) {
   if(pages.has(email)){page=pages.get(email);await goto('/dashboard');return;}
   page=await browser.newPage({viewport:{width:1440,height:1000}});
   page.on('pageerror',e=>errors.push(e.message));
   await goto('/login'); await page.locator('[name=email]').fill(email+'@fineworth.test');
   await page.locator('[name=password]').fill('FineworthDemo!27');
   await page.locator('button[type=submit]').click(); await page.waitForURL('**/dashboard');
   pages.set(email,page);
 }
 await login('admin');
 await goto('/approvals');
 const obsolete = page.locator('.review-card').filter({hasText:/Bunnahabhain 2016.*November demo/});
 if(await obsolete.count()){
   await obsolete.locator('[name=reason]').fill('Superseded by the signing-ready demonstration. Original snapshot retained.');
   await obsolete.getByRole('button',{name:'Return for correction'}).click(); await page.waitForLoadState();
 }
 if(process.argv.includes('--finish')) {
   await goto('/dashboard');
   if(await page.locator('.document-row').filter({hasText:/Bunnahabhain 2016.*November demo/}).count()) throw new Error('Superseded draft remains active');
   await page.screenshot({path:path.join(out,'admin-action-dashboard.png'),fullPage:true,animations:'disabled'});
   await page.setViewportSize({width:390,height:844});
   await page.screenshot({path:path.join(out,'admin-dashboard-mobile.png'),fullPage:true,animations:'disabled'});
   console.log('Superseded draft returned; dashboard screenshots refreshed.');
   await browser.close(); return;
 }
 await goto('/clients');
 if (!await page.getByText('evelyn.demo@fineworth.test',{exact:true}).count()) {
   await page.locator('[data-open="account-modal"]').click();
   const f=page.locator('#account-modal');
   await f.locator('[name=name]').fill('Evelyn Hart — demonstration');
   await f.locator('[name=email]').fill('evelyn.demo@fineworth.test');
   await f.locator('[name=password]').fill('FineworthDemo!27');
   await f.getByLabel('Oliver Grant',{exact:true}).check();
   await f.getByRole('button',{name:'Create account'}).click();
   await page.waitForLoadState();
 }
 await goto('/managers'); await page.screenshot({path:path.join(out,'manager-multiple-clients.png'),fullPage:true,animations:'disabled'});
 await login('manager'); await goto('/clients');
 if (!await page.getByText('evelyn.demo@fineworth.test',{exact:true}).count()) throw new Error('Second assigned client missing');
 async function createDeal(type, clientLabel, reference) {
   await goto('/deals');
   const existing=page.locator('.document-row').filter({hasText:type==='wine'?'Château Margaux 2018 · signing-ready demo':'Bunnahabhain 2016 · signing-ready demo'});
   if (await existing.count()) {await existing.first().getByText('Open deal').click();return page.url();}
   const f=page.locator('[data-deal-form]');
   await f.locator('[name=client_id]').selectOption({label:clientLabel});
   await f.locator('[name=type]').selectOption(type);
   const fields={client_address:'14 Example Square, London, W1A 1AA',name:type==='wine'?'Château Margaux 2018 · signing-ready demo':'Bunnahabhain 2016 · signing-ready demo',reference,
    producer:type==='wine'?'Château Margaux':'Bunnahabhain',storage:type==='wine'?'London City Bond':'Islay Bonded Storage',unit_price:type==='wine'?'250':'12000',deposit:type==='wine'?'1500':'3000',deposit_reference:'DEMO-EXTERNAL-20261103',deposit_date:'2026-11-03',balance_due:'2026-12-03'};
   if(type==='wine') Object.assign(fields,{quantity:'24',vintage:'2018',bottle_ml:'750'});
   else Object.assign(fields,{cask_type:'Sherry hogshead',cask_size:'250',ola:'160',rla:'145.61',abv:'59.8',measurement_date:'2026-10-28'});
   for(const [key,val] of Object.entries(fields)) await f.locator(`[name=${key}]`).fill(val);
   for(const [key,val] of Object.entries({markup_rate:'5',management_rate:'2.5',brokerage_rate:'2'})) await f.locator(`[name=${key}]`).selectOption(val);
   await page.screenshot({path:path.join(out,`deal-${type}-form.png`),fullPage:true,animations:'disabled'});
   await f.getByRole('button',{name:'Save deal draft'}).click(); await page.waitForURL(/\/deals\/\d+$/);
   return page.url();
 }
 const wineUrl=await createDeal('wine','Evelyn Hart — demonstration','DEMO-WINE-SIGN-20261103');
 if (await page.getByRole('button',{name:'Generate agreement PDF'}).count()) {await page.getByRole('button',{name:'Generate agreement PDF'}).click();await page.waitForLoadState();}
 const winePdf=await page.getByText('Open agreement PDF').getAttribute('href');
 let r=await page.request.get('http://127.0.0.1:8000'+winePdf); if(!r.ok())throw new Error('Wine PDF unavailable');
 fs.writeFileSync(path.join(pdfOut,'wine-deal-demonstration.pdf'),await r.body());
 await login('admin'); await goto('/dashboard');await page.screenshot({path:path.join(out,'admin-action-dashboard.png'),fullPage:true,animations:'disabled'});
 await goto('/approvals'); const card=page.locator('.review-card').filter({hasText:'Château Margaux 2018 · signing-ready demo'});
 if(await card.count()){await card.getByRole('button',{name:'Approve & publish'}).click();await page.waitForLoadState();}
 await login('evelyn.demo');await page.goto(wineUrl);await page.screenshot({path:path.join(out,'client-deal-agreement.png'),fullPage:true,animations:'disabled'});
 if(await page.getByRole('button',{name:'Record review'}).count()){await page.getByLabel('I have read this agreement.').check();await page.getByRole('button',{name:'Record review'}).click();await page.waitForLoadState();}
 await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(out,'client-deal-mobile.png'),fullPage:true,animations:'disabled'});
 const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth);
 if(overflow)throw new Error('Mobile page overflows horizontally');
 await page.setViewportSize({width:1440,height:1000});
 await login('manager');
 // The existing display name may have been corrected through the earlier approval demo.
 await goto('/deals');
 const sophiaLabel=await page.locator('[name=client_id] option').filter({hasText:'Sophia'}).first().textContent();
 const whiskyUrl=await createDeal('whisky',sophiaLabel,'DEMO-CASK-SIGN-20261103');
 if(await page.getByRole('button',{name:'Generate agreement PDF'}).count()){await page.getByRole('button',{name:'Generate agreement PDF'}).click();await page.waitForLoadState();}
 const whiskyPdf=await page.getByText('Open agreement PDF').getAttribute('href');
 r=await page.request.get('http://127.0.0.1:8000'+whiskyPdf);if(!r.ok())throw new Error('Whisky PDF unavailable');
 fs.writeFileSync(path.join(pdfOut,'whisky-deal-demonstration.pdf'),await r.body());
 await login('admin');await goto('/dashboard');await page.screenshot({path:path.join(out,'admin-action-dashboard.png'),fullPage:true,animations:'disabled'});
 await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(out,'admin-dashboard-mobile.png'),fullPage:true,animations:'disabled'});
 if(await page.evaluate(()=>document.documentElement.scrollWidth>window.innerWidth))throw new Error('Admin mobile overflow');
 if(errors.length)throw new Error(errors.join('\n'));
 fs.writeFileSync(path.join(root,'docs/demo-records.json'),JSON.stringify({wineUrl,whiskyUrl,winePdf,whiskyPdf},null,2));
 console.log(JSON.stringify({wineUrl,whiskyUrl,errors,verified:'manager assignment, wine approval/client review, whisky draft, desktop/mobile, private PDFs'}));
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});

