import { expect, type Locator, type Page } from '@playwright/test';

export async function clickFirstAddableItem(page: Page): Promise<void> {
  const cards = page.locator('.item-wrapper [data-item-id]');
  const count = await cards.count();
  expect(count, 'POS item grid should expose at least one item card').toBeGreaterThan(0);

  for (let index = 0; index < Math.min(count, 30); index++) {
    const card = cards.nth(index);
    const canAdd = await card.getAttribute('data-can-add');
    if (canAdd === '0') {
      continue;
    }
    await card.click();
    return;
  }

  await cards.first().click();
}

export async function readCartNet(page: Page): Promise<number> {
  const net = page.locator('#net_val');
  await expect(net).toBeAttached();
  const value = parseFloat((await net.inputValue()) || '0');
  return Number.isFinite(value) ? value : 0;
}

export async function openPaymentModal(page: Page): Promise<void> {
  const payButton = page.locator('[data-bs-target="#paymentModal"], button[data-bs-target="#paymentModal"]').first();
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
  await page.locator('.pos-save-order-btn').click();
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
  return page.locator('#order-items .order-item-row, #order-items tr, #order-items .pos-cart-line').count();
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
