const { chromium } = require('C:/Users/lenovo/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const output = path.resolve(__dirname, '../../output/login-review');
fs.mkdirSync(output, { recursive: true });
(async () => {
    const browser = await chromium.launch({ headless: true });
    const results = [];
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        for (const [name, width, height] of [['full-hd', 1920, 1080], ['ultrawide', 2560, 1080], ['desktop', 1440, 900], ['short-desktop', 1280, 650], ['tablet', 900, 1000], ['mobile', 390, 844], ['small-mobile', 320, 640]]) {
            await page.setViewportSize({ width, height });
            const response = await page.goto('http://127.0.0.1:8000/login');
            assert.equal(response.status(), 200);
            await page.evaluate(() => document.fonts.ready);
            await page.locator('.vault-login-brand img').evaluate(img => img.decode());
            await page.screenshot({ path: path.join(output, `login-${name}.png`), fullPage: true });
            const layout = await page.evaluate(() => ({
                width: document.documentElement.clientWidth,
                scrollWidth: document.documentElement.scrollWidth,
                containerWidth: document.querySelector('.vault-login').getBoundingClientRect().width,
                containerX: document.querySelector('.vault-login').getBoundingClientRect().x,
                backgroundSize: getComputedStyle(document.querySelector('.vault-login')).backgroundSize,
                background: getComputedStyle(document.querySelector('.vault-login')).backgroundImage,
                blur: getComputedStyle(document.querySelector('.vault-login-panel')).backdropFilter,
                formX: document.querySelector('.vault-login-panel').getBoundingClientRect().x,
                formWidth: document.querySelector('.vault-login-panel').getBoundingClientRect().width,
            }));
            assert.ok(layout.scrollWidth <= layout.width, `${name}: horizontal overflow`);
            assert.equal(layout.containerWidth, layout.width, `${name}: login must fill viewport width`);
            assert.equal(layout.containerX, 0, `${name}: login must start at viewport edge`);
            assert.ok(layout.backgroundSize.split(',').every(size => size.trim() === 'cover'));
            assert.ok(layout.background.includes('login-background.webp'));
            assert.equal(layout.blur, 'blur(18px)');
            const expectedCenter = layout.width > 900 ? layout.width / 4 : layout.width / 2;
            assert.ok(Math.abs(layout.formX + layout.formWidth / 2 - expectedCenter) < 1, `${name}: form must center in its responsive column`);
            results.push({ name, width, height, ...layout });
        }
        for (const selector of ['#login-email', '#password']) {
            const input = page.locator(selector);
            assert.equal(await input.getAttribute('placeholder'), null);
            await input.focus();
            await page.waitForFunction(id => getComputedStyle(document.querySelector(id).nextElementSibling).fontSize === '10px', selector);
            await input.fill('non-empty');
            await input.blur();
            assert.ok(await input.evaluate(el => el.parentElement.classList.contains('has-value')));
            await input.fill('');
            await input.blur();
            await page.waitForFunction(id => getComputedStyle(document.querySelector(id).nextElementSibling).fontSize === '14px', selector);
        }
        await page.setViewportSize({ width: 1920, height: 1080 });
        await page.locator('#login-email').fill('preview@example.test');
        await page.locator('#password').fill('preview-only');
        await page.locator('#password').blur();
        await page.waitForFunction(() => [...document.querySelectorAll('.vault-login-field > label')].every(label => getComputedStyle(label).fontSize === '10px'));
        await page.screenshot({ path: path.join(output, 'login-floating-labels.png'), fullPage: true });
        await page.getByRole('button', { name: 'Show password' }).click();
        assert.equal(await page.locator('#password').getAttribute('type'), 'text');
        assert.ok(await page.locator('.password-hide-icon').isVisible());
        assert.equal(await page.locator('.password-show-icon').isVisible(), false);
        await page.getByRole('button', { name: 'Hide password' }).click();
        assert.equal(await page.locator('#password').getAttribute('type'), 'password');
        assert.ok(await page.locator('.password-show-icon').isVisible());
        assert.equal(await page.locator('.password-hide-icon').isVisible(), false);
        await page.locator('#login-email').fill('login-preview@example.invalid');
        await page.getByRole('button', { name: 'Sign in & unlock vault' }).click();
        await page.getByRole('alert').waitFor();
        assert.equal(await page.locator('#login-email').inputValue(), 'login-preview@example.invalid');
        assert.ok(await page.locator('#login-email').evaluate(el => el.parentElement.classList.contains('has-value')));
        assert.equal(await page.locator('#password').inputValue(), '');
        await page.screenshot({ path: path.join(output, 'login-validation.png'), fullPage: true });
        assert.deepEqual(errors, []);
        fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({ results, errors, passwordToggle: 'passed', invalidLogin: 'passed' }, null, 2));
        console.log('Seven responsive layouts passed. Floating labels verified for empty, focused, filled, cleared and validation-restored fields. Password toggle and invalid-login handling passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
