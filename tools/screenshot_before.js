// Captures "before" screenshots (original design, pre-refresh) for comparison.
const { chromium } = require('playwright');
const path = require('path');

const BASE = 'http://127.0.0.1:8877';
const OUT = '/tmp/shots';

async function login(page, username, password) {
  await page.goto(BASE + '/login.php');
  await page.fill('input[name="username"]', username);
  await page.fill('input[name="password"]', password);
  await page.click('form button.btn');
  await page.waitForLoadState('networkidle');
}

async function setTheme(page, theme) {
  await page.goto(BASE + '/account.php');
  await page.waitForLoadState('networkidle');
  await page.check(`input[name="pref_theme"][value="${theme}"]`);
  await page.locator('form:has(input[name="pref_theme"]) button.btn').first().click();
  await page.waitForLoadState('networkidle');
}

(async () => {
  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });

  {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    await login(page, 'admin', 'DevAdmin47!');
    await setTheme(page, 'light');
    await page.goto(BASE + '/dashboard.php');
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: path.join(OUT, 'before_01_admin_light_dashboard.png'), fullPage: true });
    await setTheme(page, 'dark');
    await page.goto(BASE + '/dashboard.php');
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: path.join(OUT, 'before_02_admin_dark_dashboard.png'), fullPage: true });
    await ctx.close();
  }

  {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    await login(page, 'lehrer1', 'DevTeach47!');
    await setTheme(page, 'light');
    await page.goto(BASE + '/teacher/index.php');
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: path.join(OUT, 'before_03_teacher_light_index.png'), fullPage: true });
    await page.goto(BASE + '/teacher/manage.php');
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: path.join(OUT, 'before_04_teacher_light_manage.png'), fullPage: true });
    await page.goto(BASE + '/account.php');
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: path.join(OUT, 'before_05_teacher_account.png'), fullPage: true });
    await ctx.close();
  }

  await browser.close();
  console.log('done');
})().catch((e) => { console.error(e); process.exit(1); });
