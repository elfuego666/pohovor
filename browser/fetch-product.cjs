'use strict';

const { chromium } = require('playwright');

function validateUrl(value) {
  const url = new URL(value);
  if (url.protocol !== 'https:' || !['alza.sk', 'www.alza.sk'].includes(url.hostname)
      || url.username || url.password || url.port) {
    throw new Error('Povolené sú iba HTTPS produktové URL na alza.sk.');
  }
  return url.href;
}

async function readProductPage(page, url, timeout) {
  validateUrl(url);
  // Validate initiated top-level navigation. Final URL is also checked below,
  // because server-side redirects are not always intercepted by page routing.
  await page.route('**/*', async route => {
    const request = route.request();
    if (request.isNavigationRequest() && request.frame() === page.mainFrame()) {
      try { validateUrl(request.url()); }
      catch { await route.abort('blockedbyclient'); return; }
    }
    await route.fallback();
  });
  const deadline = Date.now() + timeout;
  const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout });
  validateUrl(page.url());
  if (response && [404, 410].includes(response.status())) {
    throw new Error(`Produkt neexistuje (HTTP ${response.status()}).`);
  }
  try {
    // A challenge can initially return 403, then navigate after manual confirmation.
    await page.waitForFunction(() => {
      const heading = document.querySelector('h1');
      if (!heading?.textContent.trim()) return false;
      if (document.querySelector('#parameters, #detailDescription, #description')) return true;
      const findProduct = value => {
        if (!value || typeof value !== 'object') return false;
        if ([].concat(value['@type'] || []).includes('Product')) return true;
        return Object.values(value).some(findProduct);
      };
      return [...document.querySelectorAll('script[type="application/ld+json"]')].some(script => {
        try { return findProduct(JSON.parse(script.textContent)); } catch { return false; }
      });
    }, null, { timeout: Math.max(1, deadline - Date.now()) });
  } catch (error) {
    if (error.name !== 'TimeoutError') throw error;
    throw new Error('Detail produktu sa nenačítal v časovom limite. Ak Chrome zobrazuje ochrannú výzvu, potvrďte ju ručne a skúste príkaz znova.');
  }
  validateUrl(page.url());
  // Trigger lazy content in the parameters tab and allow the DOM to settle.
  const parameters = page.locator('#parameters');
  if (await parameters.count()) {
    await parameters.first().scrollIntoViewIfNeeded({ timeout: Math.min(5000, Math.max(1, deadline - Date.now())) }).catch(() => {});
  }
  await page.waitForTimeout(Math.min(1500, Math.max(0, deadline - Date.now())));
  return page.content();
}

async function main() {
  let input = '';
  for await (const chunk of process.stdin) input += chunk;
  const config = JSON.parse(input);
  validateUrl(config.url);
  if (!Number.isInteger(config.timeout) || config.timeout < 1) throw new Error('Neplatný timeout.');
  let context;
  let browser;
  const timer = setTimeout(async () => {
    process.stderr.write('Chrome prekročil časový limit. Ochrannú výzvu treba potvrdiť ručne.\n');
    try { await context?.close(); } finally { process.exit(1); }
  }, config.timeout + 10000);
  try {
    const launch = {
      headless: config.headless === true,
      timeout: Math.min(config.timeout, 30000),
      ...(config.executablePath ? { executablePath: config.executablePath } : { channel: 'chrome' }),
    };
    const options = { locale: 'sk-SK', viewport: { width: 1366, height: 900 } };
    if (config.profileDirectory) {
      context = await chromium.launchPersistentContext(config.profileDirectory, { ...launch, ...options });
    } else {
      browser = await chromium.launch(launch);
      context = await browser.newContext(options);
    }
    const page = context.pages()[0] || await context.newPage();
    const html = await readProductPage(page, config.url, config.timeout);
    process.stdout.write(JSON.stringify({ html }));
  } finally {
    await context?.close();
    await browser?.close();
    clearTimeout(timer);
  }
}

if (require.main === module) {
  main().catch(error => {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 1;
  });
}

module.exports = { validateUrl, readProductPage };
