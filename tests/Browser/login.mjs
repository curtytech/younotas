const { chromium } = await import(process.env.PLAYWRIGHT_MODULE);
import assert from 'node:assert/strict';
import {writeFile, readFile} from 'node:fs/promises';
const url = process.env.BROWSER_TEST_URL;
assert.match(url ?? '', /^http:\/\/(127\.0\.0\.1|localhost):[0-9]+$/);
const browser = await chromium.launch({headless: true, executablePath: process.env.CHROMIUM_PATH});
const field = (page, name) => page.locator('[id="data.' + name + '"]');
let currentPage;
try {
  for (const [width, height] of [[1440, 900], [390, 844]]) {
    const context = await browser.newContext({viewport: {width, height}, screen: {width, height}, isMobile: width < 1024, hasTouch: width < 1024});
    let authorizedPdfRequests = 0;
    await context.route('**/*', async route => {
      if (route.request().url() === 'https://homologacao.focusnfe.com.br/arquivos/e2e.pdf') {
        authorizedPdfRequests++;
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
    console.log('Login real ' + width + 'px: passou');
    await page.goto(url + '/admin/clients/create');
    if (width < 1024 && await page.locator('.fi-sidebar-close-overlay').isVisible()) {
      await page.locator('.fi-sidebar-close-overlay').click({position: {x: width - 8, y: 80}});
    }
    await field(page, 'name').fill('Cliente E2E ' + width);
    await field(page, 'phone').fill('21999999999');
    await page.locator('.choices').filter({has: field(page, 'document_type')}).click();
    await page.getByRole('option', {name: 'CPF', exact: true}).click();
    await field(page, 'document').fill('12345678901');
    await field(page, 'address').fill('Rua de teste');
    await field(page, 'city').fill('Magé');
    await field(page, 'state').fill('XX');
    await field(page, 'ibge_code').fill('123');
    await page.getByRole('button', {name: 'Criar', exact: true}).click();
    for (const text of ['Informe um CPF válido.', 'Informe uma UF válida.', 'O código IBGE deve ter 7 dígitos.']) {
      await page.getByText(text, {exact: true}).waitFor();
    }
    assert.equal(await field(page, 'name').inputValue(), 'Cliente E2E ' + width);
    assert.equal(await field(page, 'document').inputValue(), '12345678901');
    assert.equal(page.url(), url + '/admin/clients/create');
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'Formulário sem overflow horizontal');
    await page.screenshot({path: process.env.BROWSER_TEST_DIR + '/client-errors-' + width + '.png', fullPage: true});
    await field(page, 'document').fill(width === 1440 ? '11144477735' : '52998224725');
    await field(page, 'state').fill('RJ');
    await field(page, 'ibge_code').fill('3302502');
    await page.getByRole('button', {name: 'Criar', exact: true}).click();
    await page.waitForURL(/\/clients\/\d+\/edit/, {timeout: 15000});
    assert.equal(await field(page, 'name').inputValue(), 'Cliente E2E ' + width);
    console.log('Cliente inválido/valores preservados/cadastro válido ' + width + 'px: passou');
    await page.goto(url + '/admin/products/create');
    await field(page, 'name').fill('Produto E2E ' + width);
    await field(page, 'cfop_code').fill('5102');
    await field(page, 'sale_price').fill('10000');
    await field(page, 'stock_quantity').fill('10');
    await page.getByRole('button', {name: 'Criar', exact: true}).click();
    await page.waitForURL(/\/products\/\d+\/edit/);
    const productEditUrl = page.url();
    console.log('Cadastro produto ' + width + 'px: passou');
    await page.goto(url + '/admin/sales/create');
    await page.locator('.choices').filter({has: field(page, 'client_id')}).click();
    await page.getByRole('option', {name: new RegExp('Cliente E2E ' + width)}).click();
    const productSelect = page.locator('select[id$=".product_id"]');
    await page.locator('.choices').filter({has: productSelect}).click();
    await page.getByRole('option', {name: 'Produto E2E ' + width, exact: true}).click();
    await page.getByRole('button', {name: 'Criar', exact: true}).click();
    await page.waitForURL(/\/sales\/\d+\/edit/);
    const saleEditUrl = page.url();
    console.log('Cadastro venda ' + width + 'px: passou');
    await page.getByRole('button', {name: 'Mais ações', exact: true}).click();
    await page.getByRole('button', {name: 'Pré-visualizar DANFE', exact: true}).click();
    await page.getByText('Revise os dados antes de abrir a DANFE', {exact: true}).waitFor();
    await page.getByText(new RegExp('precisa ter NCM de 8 dígitos')).waitFor();
    assert.equal(context.pages().length, 1, 'Pendência fiscal não abre nova aba');
    assert.equal(await page.getByRole('link', {name: 'Abrir DANFE', exact: true}).count(), 0);
    await page.goto(productEditUrl);
    await field(page, 'ncm_code').fill('87089990');
    const saved = page.waitForResponse(response => response.url().endsWith('/livewire/update') && response.request().method() === 'POST');
    await page.getByRole('button', {name: 'Salvar alterações', exact: true}).click();
    assert.equal((await saved).status(), 200);
    await page.goto(saleEditUrl);
    console.log('NCM ausente: mensagem útil, sem aba e correção pelo formulário ' + width + 'px: passou');
    await page.getByRole('button', {name: 'Mais ações', exact: true}).click();
    await page.getByRole('button', {name: 'Pré-visualizar DANFE', exact: true}).click();
    await page.getByText('Dados conferidos', {exact: true}).waitFor();
    const pdfLink = page.getByRole('link', {name: 'Abrir DANFE', exact: true});
    const pdfResponse = await context.request.get(await pdfLink.getAttribute('href'));
    assert.equal(pdfResponse.status(), 200);
    assert.equal((await pdfResponse.body()).subarray(0, 4).toString(), '%PDF');
    const previewPopupPromise = page.waitForEvent('popup');
    await pdfLink.click();
    const previewPopup = await previewPopupPromise;
    await previewPopup.waitForURL(/\/danfe-preview$/);
    await previewPopup.close();
    await page.locator('.fi-no-notification').filter({has: page.getByText('Dados conferidos', {exact: true})}).locator('.fi-no-notification-close-btn').click();
    await page.getByText('Dados conferidos', {exact: true}).waitFor({state: 'hidden'});
    await page.keyboard.press('Escape');
    await page.getByRole('button', {name: 'Emitir NF-e', exact: true}).click();
    await page.getByRole('dialog').getByRole('button', {name: /Confirmar/}).dblclick();
    await page.getByRole('link', {name: 'Abrir DANFE', exact: true}).filter({hasText: 'Abrir DANFE'}).first().waitFor();
    await page.reload();
    const authorizedLink = page.getByRole('link', {name: 'Abrir DANFE', exact: true});
    await authorizedLink.waitFor();
    assert.equal(await authorizedLink.getAttribute('href'), 'https://homologacao.focusnfe.com.br/arquivos/e2e.pdf');
    assert.equal(await page.getByRole('button', {name: 'Emitir NF-e', exact: true}).count(), 0);
    const authorizedPopupPromise = page.waitForEvent('popup');
    await authorizedLink.click();
    const authorizedPopup = await authorizedPopupPromise;
    await authorizedPopup.waitForURL('https://homologacao.focusnfe.com.br/arquivos/e2e.pdf');
    assert.equal(authorizedPdfRequests, 1);
    await authorizedPopup.close();
    console.log('Prévia PDF/emissão simulada/DANFE autorizada ' + width + 'px: passou');
    await page.screenshot({path: process.env.BROWSER_TEST_DIR + '/authorized-' + width + '.png', fullPage: true});
    assert.deepEqual(failures, [], 'Sem erros JavaScript');
    await context.close();
  }
  const calls = (await readFile(process.env.BROWSER_TEST_DIR + '/requests.jsonl', 'utf8')).trim().split('\n').map(line => JSON.parse(line));
  assert.equal(calls.filter(call => call.method === 'POST' && call.path === '/v2/nfe').length, 2, 'Uma emissão por venda mesmo com clique duplo');
} catch (error) {
  if (currentPage && !currentPage.isClosed()) {
    await currentPage.screenshot({path: process.env.BROWSER_TEST_DIR + '/failure.png', fullPage: true});
    await writeFile(process.env.BROWSER_TEST_DIR + '/failure.txt', await currentPage.locator('body').innerText());
    console.error(await currentPage.locator('body').innerText());
  }
  throw error;
} finally { await browser.close(); }
