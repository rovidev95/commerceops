/**
 * Submit CommerceOps plugin to WordPress.org (visible browser, supports manual login).
 */
import { chromium } from 'playwright';
import { existsSync, writeFileSync, mkdirSync } from 'fs';
import { dirname, resolve } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const root = resolve(__dirname, '..');
const zipPath = process.env.CO_PLUGIN_ZIP || resolve(root, 'dist/woo-ai-schema.zip');
const outPath = resolve(root, 'data/wporg-submit-result.json');
const loginWaitMs = Number(process.env.CO_LOGIN_WAIT_MS || 300000);
const wpUser = process.env.WPORG_USER || 'rovidev95@gmail.com';
const wpPass = process.env.WPORG_PASSWORD || '';
const systemProfile = 'C:\\Users\\Fran\\AppData\\Local\\Google\\Chrome\\User Data';
const useSystem = existsSync(systemProfile);

const ADD_URL = 'https://wordpress.org/plugins/developers/add/';
const LOGIN_URL = 'https://login.wordpress.org/?redirect_to=https%3A%2F%2Fwordpress.org%2Fplugins%2Fdevelopers%2Fadd%2F';

async function shot(page, name) {
  await page.screenshot({ path: resolve(root, `data/wporg-${name}.png`), fullPage: true }).catch(() => {});
}

async function tryLogin(page) {
  await page.goto(LOGIN_URL, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await shot(page, 'login');

  if (wpPass) {
    await page.fill('input[name="log"], input#user_login, input[type="email"]', wpUser).catch(() => {});
    await page.fill('input[name="pwd"], input#user_pass, input[type="password"]', wpPass).catch(() => {});
    await page.locator('input[type="submit"], button[type="submit"]').first().click().catch(() => {});
    await page.waitForTimeout(5000);
    return;
  }

  // Google / WordPress.com buttons if present
  for (const sel of ['text=Google', 'text=Continue with Google', 'text=Log in with Google']) {
    const btn = page.locator(sel).first();
    if (await btn.count()) {
      await btn.click().catch(() => {});
      await page.waitForTimeout(4000);
      break;
    }
  }
}

async function waitForUploadForm(page) {
  const start = Date.now();
  while (Date.now() - start < loginWaitMs) {
    await page.goto(ADD_URL, { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {});
    await page.waitForTimeout(2500);

    const fileInput = page.locator('input[type="file"]').first();
    if (await fileInput.count()) {
      return fileInput;
    }

    const body = await page.locator('body').innerText();
    if (/please log in|log in to your wordpress/i.test(body)) {
      await tryLogin(page);
    }

    const elapsed = Math.round((Date.now() - start) / 1000);
    console.log(`Waiting for login/upload form... ${elapsed}s (complete login in the browser window)`);
    await shot(page, `wait-${elapsed}`);
    await page.waitForTimeout(5000);
  }
  throw new Error('Timeout waiting for upload form.');
}

async function main() {
  if (!existsSync(zipPath)) throw new Error(`Missing zip: ${zipPath}`);
  mkdirSync(resolve(root, 'data'), { recursive: true });

  const launchOpts = {
    headless: false,
    viewport: { width: 1280, height: 900 },
    acceptDownloads: true,
    slowMo: 50,
  };
  if (useSystem) launchOpts.args = ['--profile-directory=Default'];

  const browser = await chromium.launchPersistentContext(
    useSystem ? systemProfile : resolve(root, 'automation/.chrome-profile'),
    launchOpts
  );

  const page = browser.pages()[0] || (await browser.newPage());
  const result = { ok: false, ts: new Date().toISOString(), zip: zipPath, steps: [] };

  try {
    result.steps.push('wait_form');
    const fileInput = await waitForUploadForm(page);

    result.steps.push('upload');
    await fileInput.setInputFiles(zipPath);
    await page.waitForTimeout(2000);
    await shot(page, 'upload-filled');

    const submit = page.locator('input[type="submit"], button[type="submit"]').first();
    if (await submit.count()) {
      result.steps.push('submit');
      await submit.click();
      await page.waitForTimeout(10000);
    }

    const body = await page.locator('body').innerText();
    result.url = page.url();
    result.body_snippet = body.slice(0, 800);
    result.ok = /thank|received|submitted|success|queued|review/i.test(body);
    await shot(page, 'final');
  } catch (err) {
    result.error = String(err.message || err);
    await shot(page, 'error');
  } finally {
    writeFileSync(outPath, JSON.stringify(result, null, 2));
    console.log(JSON.stringify(result, null, 2));
    if (!result.ok) {
      console.log('Browser stays open 60s for manual login/submit if needed...');
      await page.waitForTimeout(60000);
    }
    await browser.close();
  }

  process.exit(result.ok ? 0 : 1);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
