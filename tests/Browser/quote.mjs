// Fluxo completo: orçamento com produto e serviço → PDF → venda → NF-e → DANFE.
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE);
import assert from 'node:assert/strict';
import {writeFile, readFile} from 'node:fs/promises';
const url = process.env.BROWSER_TEST_URL;
assert.match(url ?? '', /^http:\/\/(127\.0\.0\.1|localhost):[0-9]+$/);
const danfeUrl = 'https://homologacao.focusnfe.com.br/arquivos/e2e.pdf';
const notice = 'Este serviço não entra na venda gerada pelo orçamento.';
const countEmissions = async () => (await readFile(process.env.BROWSER_TEST_DIR + '/requests.jsonl', 'utf8')).trim().split('\n')
  .map(line => JSON.parse(line)).filter(call => call.method === 'POST' && call.path === '/v2/nfe').length;
const choose = async (page, select, option) => {
  await page.locator('.choices').filter({has: select}).click();
  await page.getByRole('option', {name: option, exact: true}).click();
};
const browser = await chromium.launch({headless: true, executablePath: process.env.CHROMIUM_PATH});
let currentPage;
try {
  for (const [width, height] of [[1440, 900], [390, 844]]) {
    const context = await browser.newContext({viewport: {width, height}, screen: {width, height}, isMobile: width < 1024, hasTouch: width < 1024});
    await context.route('**/*', async route => {
      if (route.request().url() === danfeUrl) {
        return route.fulfill({status: 200, contentType: 'application/pdf', body: await readFile(process.env.BROWSER_TEST_DIR + '/danfe.pdf')});
      }
      return new URL(route.request().url()).origin === url ? route.continue() : route.abort();
    });
    const page = await context.newPage();
    currentPage = page;
    const failures = [];
    page.on('pageerror', error => failures.push(error.message));
    await page.goto(url + '/admin/login');
    await page.locator('input[type=email]').fill('admin@example.test');
    await page.locator('input[type=password]').fill('senha-sintetica');
    await page.getByRole('button', {name: 'Login', exact: true}).click();
    await page.waitForURL(/\/admin\/?$/, {timeout: 15000});
    const emissionsBefore = await countEmissions().catch(() => 0);

    await page.goto(url + '/admin/quotes/create');
    if (width < 1024 && await page.locator('.fi-sidebar-close-overlay').isVisible()) {
      await page.locator('.fi-sidebar-close-overlay').click({position: {x: width - 8, y: 80}});
    }
    await choose(page, page.locator('[id="data.client_id"]'), 'Cliente Orçamento E2E');
    await choose(page, page.locator('select[id$=".product_id"]').first(), 'Produto Orçamento E2E');
    await page.locator('.fi-fo-repeater-item').first().getByText('R$ 50,00').first().waitFor();
    await page.locator('input[id$=".quantity"]').first().fill('2');
    await page.locator('input[id$=".quantity"]').first().blur();
    await page.getByText('R$ 100,00').first().waitFor();
    assert.equal(await page.getByText(notice).count(), 0, 'Produto não mostra aviso de serviço');
    await page.getByRole('button', {name: 'Adicionar item', exact: true}).click();
    const items = page.locator('.fi-fo-repeater-item');
    await items.nth(1).waitFor();
    await items.nth(1).getByText('Serviço', {exact: true}).click();
    await items.nth(1).getByText(notice).waitFor();
    await items.nth(1).locator('.choices').click();
    await page.getByRole('option', {name: 'Serviço Orçamento E2E'}).click();
    await items.nth(1).getByText('R$ 120,00').first().waitFor();
    assert.equal(await items.nth(0).getByText(notice).count(), 0, 'Aviso só no item de serviço');
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Formulário do orçamento sem overflow horizontal');
    await page.screenshot({path: process.env.BROWSER_TEST_DIR + '/quote-form-' + width + '.png', fullPage: true});
    await page.emulateMedia({colorScheme: 'dark'});
    await page.evaluate(() => document.documentElement.classList.add('dark'));
    await items.nth(1).screenshot({path: process.env.BROWSER_TEST_DIR + '/quote-service-dark-' + width + '.png'});
    await page.evaluate(() => document.documentElement.classList.remove('dark'));
    await page.emulateMedia({colorScheme: 'light'});
    await page.getByRole('button', {name: 'Criar', exact: true}).click();
    await page.waitForURL(/\/quotes\/\d+\/edit/, {timeout: 15000});
    await page.getByText(notice).waitFor();
    console.log('Orçamento com produto e serviço e aviso do serviço ' + width + 'px: passou');

    const pdfHref = await page.getByRole('link', {name: 'Baixar PDF'}).getAttribute('href');
    const pdf = await context.request.get(pdfHref);
    assert.equal(pdf.status(), 200);
    assert.equal((await pdf.body()).subarray(0, 4).toString(), '%PDF');
    console.log('PDF do orçamento ' + width + 'px: passou');

    await page.getByRole('button', {name: 'Gerar venda', exact: true}).click();
    await page.getByRole('dialog').getByRole('button', {name: /Confirmar/}).click();
    await page.getByText('Os serviços do orçamento não entram na venda.', {exact: false}).waitFor();
    await page.getByRole('link', {name: 'Abrir venda', exact: true}).first().click();
    await page.waitForURL(/\/sales\/\d+\/edit/, {timeout: 15000});
    await page.mouse.wheel(0, 4000);
    await page.getByRole('cell', {name: /Produto Orçamento E2E/}).first().waitFor({timeout: 15000});
    assert.equal(await page.getByText('R$ 100,00').count() > 0, true, 'Subtotal só com 2 produtos de R$ 50,00');
    assert.equal(await page.getByText('Serviço Orçamento E2E').count(), 0, 'Serviço fora da venda');
    console.log('Venda gerada só com produtos ' + width + 'px: passou');

    await page.getByRole('button', {name: 'Emitir NF-e', exact: true}).click();
    await page.getByRole('dialog').getByRole('button', {name: /Confirmar/}).dblclick();
    await page.getByRole('link', {name: 'Abrir DANFE', exact: true}).first().waitFor({timeout: 15000});
    await page.reload();
    const danfe = page.getByRole('link', {name: 'Abrir DANFE', exact: true});
    await danfe.waitFor();
    assert.equal(await danfe.getAttribute('href'), danfeUrl);
    assert.equal(await page.getByRole('button', {name: 'Emitir NF-e', exact: true}).count(), 0);
    const popupPromise = page.waitForEvent('popup');
    await danfe.click();
    const popup = await popupPromise;
    await popup.waitForURL(danfeUrl);
    await popup.close();
    assert.equal(await countEmissions() - emissionsBefore, 1, 'Uma emissão mesmo com clique duplo');
    await page.screenshot({path: process.env.BROWSER_TEST_DIR + '/quote-sale-authorized-' + width + '.png', fullPage: true});
    console.log('NF-e da venda do orçamento e DANFE ' + width + 'px: passou');

    await page.goto(url + '/admin/quotes');
    await page.getByText('Venda #').first().waitFor();
    assert.equal(await page.getByRole('button', {name: 'Gerar venda', exact: true}).count(), 0, 'Orçamento convertido não gera outra venda');
    assert.deepEqual(failures, [], 'Sem erros JavaScript');
    await context.close();
  }
} catch (error) {
  if (currentPage && !currentPage.isClosed()) {
    await currentPage.screenshot({path: process.env.BROWSER_TEST_DIR + '/quote-failure.png', fullPage: true});
    await writeFile(process.env.BROWSER_TEST_DIR + '/quote-failure.txt', await currentPage.locator('body').innerText());
    console.error(await currentPage.locator('body').innerText());
  }
  throw error;
} finally { await browser.close(); }
