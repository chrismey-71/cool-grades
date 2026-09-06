// One-off design-review screenshot script. Not part of the app; run manually.
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

async function setPrefs(page, { theme, contrast, navStyle }) {
  await page.goto(BASE + '/account.php');
  await page.waitForLoadState('networkidle');
  if (theme) await page.check(`input[name="pref_theme"][value="${theme}"]`);
  // pref_visual_contrast only exists on the teacher branch of account.php (pre-existing).
  if (contrast && (await page.locator(`input[name="pref_visual_contrast"][value="${contrast}"]`).count()) > 0) {
    await page.check(`input[name="pref_visual_contrast"][value="${contrast}"]`);
  }
  if (navStyle) await page.check(`input[name="pref_nav_style"][value="${navStyle}"]`);
  // Submit the first "Persönliche Einstellungen speichern" button (the prefs form).
  await page.locator('form:has(input[name="pref_theme"]) button.btn').first().click();
  await page.waitForLoadState('networkidle');
}

(async () => {
  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });

  // --- Admin, light/normal/text (baseline) ---
  {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    await login(page, 'admin', 'DevAdmin47!');
    await setPrefs(page, { theme: 'light', contrast: 'normal', navStyle: 'text' });
    await page.goto(BASE + '/dashboard.php');
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: path.join(OUT, '01_admin_light_text_dashboard.png'), fullPage: true });
    await page.goto(BASE + '/admin/manage.php');
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: path.join(OUT, '02_admin_light_text_manage.png'), fullPage: true });
    await ctx.close();
  }

  // --- Admin, icon-only nav ---
  {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    await login(page, 'admin', 'DevAdmin47!');
    await setPrefs(page, { navStyle: 'icon' });
    await page.goto(BASE + '/dashboard.php');
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: path.join(OUT, '03_admin_light_iconOnly_dashboard.png'), fullPage: true });
    await ctx.close();
  }

  // --- Admin, icon+text nav, dark mode ---
  {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    await login(page, 'admin', 'DevAdmin47!');
    await setPrefs(page, { theme: 'dark', navStyle: 'icon_text' });
    await page.goto(BASE + '/dashboard.php');
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: path.join(OUT, '04_admin_dark_iconText_dashboard.png'), fullPage: true });
    await page.goto(BASE + '/admin/manage.php');
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: path.join(OUT, '05_admin_dark_iconText_manage.png'), fullPage: true });
    await ctx.close();
  }

  // --- Admin: account.php itself, to show the new settings panel ---
  {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    await login(page, 'admin', 'DevAdmin47!');
    await setPrefs(page, { theme: 'light', navStyle: 'text' });
    await page.goto(BASE + '/account.php');
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: path.join(OUT, '08_admin_account_settings.png'), fullPage: true });
    await ctx.close();
  }

  // --- Teacher, dark + high contrast (verify contrast toggle still works) ---
  {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    await login(page, 'lehrer1', 'DevTeach47!');
    await setPrefs(page, { theme: 'dark', contrast: 'high', navStyle: 'text' });
    await page.goto(BASE + '/teacher/manage.php');
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: path.join(OUT, '06_teacher_dark_highContrast_manage.png'), fullPage: true });
    await ctx.close();
  }

  // --- Teacher, light + high contrast ---
  {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    await login(page, 'lehrer1', 'DevTeach47!');
    await setPrefs(page, { theme: 'light', contrast: 'high', navStyle: 'text' });
    await page.goto(BASE + '/teacher/manage.php');
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: path.join(OUT, '07_teacher_light_highContrast_manage.png'), fullPage: true });
    await ctx.close();
  }

  // --- Teacher, light/text baseline + icon nav + reports (buttons/danger colors) ---
  {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    await login(page, 'lehrer1', 'DevTeach47!');
    await setPrefs(page, { theme: 'light', contrast: 'normal', navStyle: 'text' });
    await page.goto(BASE + '/teacher/index.php');
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: path.join(OUT, '09_teacher_light_text_index.png'), fullPage: true });
    await setPrefs(page, { navStyle: 'icon' });
    await page.goto(BASE + '/teacher/index.php');
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: path.join(OUT, '10_teacher_light_iconOnly_index.png'), fullPage: true });
    await page.goto(BASE + '/teacher/manage.php');
    await page.waitForLoadState('networkidle');
    await page.screenshot({ path: path.join(OUT, '11_teacher_light_iconOnly_manage.png'), fullPage: true });
    await ctx.close();
  }

  await browser.close();
  console.log('done');
})().catch((e) => { console.error(e); process.exit(1); });
