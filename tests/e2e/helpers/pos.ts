import { expect, type Locator, type Page } from '@playwright/test';

export async function clickFirstAddableItem(page: Page): Promise<void> {
  const cards = page.locator('.item-wrapper [data-item-id], .item-wrapper .item-card.itemButton');
  const count = await cards.count();
  expect(count, 'POS item grid should expose at least one item card').toBeGreaterThan(0);

  for (let index = 0; index < Math.min(count, 30); index++) {
    const card = cards.nth(index);
    const canAdd = await card.getAttribute('data-availability-can-add')
      ?? await card.getAttribute('data-can-add');
    if (canAdd === '0') {
      continue;
    }
    await card.click();
    return;
  }

  await cards.first().click();
}

export async function clearCartFromHeader(page: Page): Promise<void> {
  page.once('dialog', async dialog => {
    await dialog.accept();
  });
  await page.locator('#posClearOrderBtn').click();
  await expect(page.locator('#itemData .item-card-order')).toHaveCount(0);
}

export async function expectPremiumDarkTheme(page: Page): Promise<void> {
  await expect(page.locator('body')).toHaveClass(/pos-premium-dark/);
  const background = await page.evaluate(() => getComputedStyle(document.body).backgroundColor);
  expect(background).toBeTruthy();
}

export async function selectCategory(page: Page, categoryId: string): Promise<void> {
  await page.locator(`.category-btn[data-category="${categoryId}"]`).click();
}

export async function addItemBySearchBarcode(page: Page, barcode: string): Promise<void> {
  const search = page.locator('#posUnifiedSearch');
  await search.fill(barcode);
  await search.press('Enter');
}

export async function selectOrderModeTab(page: Page, mode: 'age1' | 'age2' | 'age3'): Promise<void> {
  await page.locator(`.pos-mode-tab[data-age-target="${mode}"]`).click();
}

export async function openRecentOrdersFromCorner(page: Page): Promise<void> {
  await page.locator('#cornerRecentOrdersBtn').click();
  await expect(page.locator('#recentOrdersModal')).toBeVisible();
}

export async function cartRowCount(page: Page): Promise<number> {
  return page.locator('#itemData .item-card-order').count();
}

export async function readCartTotalDisplay(page: Page): Promise<number> {
  const text = (await page.locator('#total_display').innerText()).replace(/[^\d.]/g, '');
  const value = parseFloat(text);
  return Number.isFinite(value) ? value : 0;
}

export async function readCartNet(page: Page): Promise<number> {
  const net = page.locator('#net_val');
  await expect(net).toBeAttached();
  const value = parseFloat((await net.inputValue()) || '0');
  return Number.isFinite(value) ? value : 0;
}

export async function openPaymentModal(page: Page): Promise<void> {
  const payButton = page.locator('.pos-order-footer .pos-pay-order-btn').first();
  await payButton.scrollIntoViewIfNeeded();
  await expect(payButton).toBeVisible();
  await payButton.click();
  await expect(page.locator('#paymentModal')).toBeVisible();
}

export async function payCashInModal(page: Page, amount?: number): Promise<void> {
  await openPaymentModal(page);
  const net = amount ?? (await readCartNet(page));
  const cashInput = page.locator('#modal_paid_cash');
  await cashInput.fill(net > 0 ? net.toFixed(2) : '1.00');

  const fundSelect = page.locator('#payment_fund_id');
  if (await fundSelect.isVisible().catch(() => false)) {
    const options = fundSelect.locator('option');
    const optionCount = await options.count();
    if (optionCount > 1) {
      await fundSelect.selectOption({ index: 1 });
    }
  }

  await page.locator('.pos-pay-confirm-btn').click();
}

export async function saveOrderOnly(page: Page): Promise<void> {
  const saveButton = page.locator('.pos-order-footer .pos-save-order-btn').first();
  await saveButton.scrollIntoViewIfNeeded();
  await saveButton.click({ force: true });
}

export async function saveTableOrderAndWait(page: Page): Promise<void> {
  let dialogMessage = '';
  page.once('dialog', async dialog => {
    dialogMessage = dialog.message();
    await dialog.accept();
  });

  const navigation = page.waitForNavigation({ waitUntil: 'load', timeout: 30_000 }).catch(error => error);
  await saveOrderOnly(page);
  const navigationResult = await navigation;

  expect(dialogMessage, `POS validation blocked table save: ${dialogMessage}`).toBe('');
  if (navigationResult instanceof Error) {
    throw navigationResult;
  }
}

export async function selectDeliveryMode(page: Page): Promise<void> {
  const deliveryModal = page.locator('#deliveryModal');
  const deliveryTab = page.locator('.pos-mode-tab[data-age-target="age3"]').first();
  if (await deliveryTab.isVisible().catch(() => false)) {
    await deliveryTab.click();
  } else {
    await deliveryModeRadio(page).check({ force: true });
  }

  await expect(deliveryModal).toBeVisible({ timeout: 3_000 }).catch(async () => {
    const addButton = page.locator('#posDeliveryAddBtn').first();
    if (await addButton.isVisible().catch(() => false)) {
      await addButton.click();
    }
  });

  await expect(deliveryModal).toBeVisible({ timeout: 10_000 });
}

export async function openTablesModal(page: Page): Promise<void> {
  const modal = page.locator('#tablesModal');
  if ((await modal.count()) > 0 && (await modal.isVisible().catch(() => false))) {
    return;
  }

  const tablesButton = page.locator('[data-bs-target="#tablesModal"]').first();
  if (await tablesButton.isVisible().catch(() => false)) {
    await tablesButton.click();
  } else {
    const tableModeTab = page.locator('.pos-mode-tab[data-age-target="age2"]').first();
    await expect(tableModeTab).toBeVisible();
    await tableModeTab.click();
  }
  await expect(modal).toBeVisible();
}

export async function selectFirstAvailableTable(page: Page): Promise<void> {
  const modal = page.locator('#tablesModal');
  const available = page.locator('.table-select-btn[data-table-case="0"]').first();
  if ((await modal.count()) === 0 || !(await modal.isVisible().catch(() => false))) {
    await openTablesModal(page);
  }

  await expect(available).toBeVisible({ timeout: 15_000 });
  await available.click();
  await page.locator('#tablesModal').waitFor({ state: 'hidden' }).catch(() => undefined);
}

export async function cartLineCount(page: Page): Promise<number> {
  return page.locator('#itemData .item-card-order, #order-items .order-item-row, #order-items tr, #order-items .pos-cart-line').count();
}

export async function increaseFirstLineQty(page: Page): Promise<void> {
  const increase = page.locator('.qty-increase').first();
  if (await increase.isVisible().catch(() => false)) {
    await increase.click();
  }
}

export function deliveryModeRadio(page: Page): Locator {
  return page.locator('#age3, input[name="age"][value="3"], input[name="order_mode"][value="delivery"]').first();
}
