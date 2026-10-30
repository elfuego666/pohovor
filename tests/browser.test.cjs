'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const { validateUrl, readProductPage } = require('../browser/fetch-product.cjs');

test('browser rejects foreign hosts and unsafe URLs', () => {
  for (const value of ['https://evil.com', 'file:///test', 'https://alza.sk.evil.com', 'http://alza.sk', 'https://user@alza.sk', 'https://alza.sk:8080']) {
    assert.throws(() => validateUrl(value));
  }
  assert.equal(validateUrl('https://www.alza.sk/product.htm'), 'https://www.alza.sk/product.htm');
});

test('Chrome renders JavaScript product content and restricts redirects', { timeout: 30000 }, async () => {
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const fixture = readFileSync(path.join(__dirname, 'fixtures/product.html'), 'utf8');
    const context = await browser.newContext();
    await context.route('https://www.alza.sk/**', async route => {
      if (route.request().url().includes('/redirect')) {
        await route.fulfill({ status: 302, headers: { location: 'https://example.com/blocked' } });
      } else if (route.request().url().includes('/challenge')) {
        await route.fulfill({ status: 403, contentType: 'text/html', body: '<h1>Please confirm you are human</h1>' });
      } else if (route.request().url().includes('/missing')) {
        await route.fulfill({ status: 404, contentType: 'text/html', body: '<h1>Not found</h1>' });
      } else {
        await route.fulfill({ contentType: 'text/html', body: '<html><head><meta charset="utf-8"></head><body><script>setTimeout(() => { document.body.innerHTML = ' + JSON.stringify(fixture.replace(/<script[\s\S]*?<\/script>/g, '')).replace(/<\//g, '<\\/') + '; }, 100)</script></body></html>' });
      }
    });
    const page = await context.newPage();
    const html = await readProductPage(page, 'https://www.alza.sk/product.htm', 5000);
    assert.match(html, /skúšobný produkt/);
    assert.match(html, /All-in-one PC/);
    await assert.rejects(readProductPage(page, 'https://www.alza.sk/challenge', 300), /časovom limite/);
    await assert.rejects(readProductPage(page, 'https://www.alza.sk/missing', 1000), /HTTP 404/);
    await assert.rejects(readProductPage(page, 'https://www.alza.sk/redirect', 2000), /Povolené/);
    await context.close();
  } finally {
    await browser.close();
  }
});
