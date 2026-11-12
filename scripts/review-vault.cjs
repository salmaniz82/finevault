const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'C:/Users/lenovo/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert = require('node:assert/strict');
(async () => {
    const browser = await chromium.launch();
    try {
        for (const scenario of ['wrong', 'full-hd', 'portrait', 'ultrawide-skip', 'reduced', 'broken', 'slow']) {
            const viewport = scenario === 'portrait' ? {width:390,height:844} :
                {width:scenario === 'ultrawide-skip' ? 2560 : 1920,height:1080};
            const context = await browser.newContext({ viewport, reducedMotion: scenario === 'reduced' ? 'reduce' : 'no-preference' });
            const page = await context.newPage();
            let posts = 0, media = 0, played = false;
            await page.exposeFunction('observePlayback', () => { played = true; });
            await page.route('**/cinematic-test-arrival', route => route.fulfill({body:'Arrived'}));
            await page.route('**/media/vault/*.mp4', async route => {
                media++;
                if (scenario === 'broken') return route.abort();
                if (scenario === 'slow') return; // Intentionally stall the media request.
                await route.continue();
            });
            await page.route('**/login', async route => {
                if (route.request().method() !== 'POST') return route.continue();
                posts++;
                await new Promise(resolve => setTimeout(resolve, 250));
                await route.fulfill({json:scenario === 'wrong' ? {errors:{email:['Incorrect credentials']}} :
                    {destination:'/cinematic-test-arrival'}, status:scenario === 'wrong' ? 422 : 200});
            });
            await page.goto('http://127.0.0.1:8000/login?animated=1');
            const video = page.locator('.unlock-film');
            await video.evaluate(el => el.addEventListener('playing', window.observePlayback));
            await page.getByLabel('Email address').fill('preview@example.test');
            await page.locator('#password').fill('test');
            assert.equal(media,0);
            assert.equal(played,false);
            await page.locator('form').evaluate(el => {el.requestSubmit();el.requestSubmit();});
            if (scenario === 'wrong') {
                await page.getByRole('alert').filter({hasText:'Incorrect credentials'}).waitFor();
                assert.equal(played,false);
                assert.equal(await page.locator('#password').inputValue(),'');
            } else {
                if (scenario === 'ultrawide-skip') await page.getByRole('button',{name:'Continue'}).click();
                await page.waitForURL('**/cinematic-test-arrival',{timeout:10000});
                if (['portrait','full-hd'].includes(scenario)) assert.ok(played,scenario+' video must play');
                if (scenario === 'reduced') assert.equal(media,0);
            }
            assert.equal(posts,1);
            console.log(scenario+' passed');
            await context.close();
        }
    } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1});
