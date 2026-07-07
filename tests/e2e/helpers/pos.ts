import { expect, type Locator, type Page } from '@playwright/test';

export async function clickFirstAddableItem(page: Page): Promise<void> {
  await clickNthAddableItem(page, 0);
}

export async function clickNthAddableItem(page: Page, index: number): Promise<void> {
  const cards = page.locator('.item-wrapper [data-item-id], .item-wrapper .item-card.itemButton');
  const count = await cards.count();
  expect(count, 'POS item grid should expose at least one item card').toBeGreaterThan(0);
  expect(index, `item index ${index} must be within grid (${count} cards)`).toBeLessThan(count);

  for (let scan = index; scan < Math.min(count, index + 30); scan++) {
    const card = cards.nth(scan);
    const canAdd = await card.getAttribute('data-availability-can-add')
      ?? await card.getAttribute('data-can-add');
    if (canAdd === '0') {
      continue;
    }
    await card.click();
    return;
  }

  await cards.nth(index).click();
}

export async function removeFirstCartLine(page: Page): Promise<void> {
  const lines = page.locator('#itemData .item-card-order');
  const before = await lines.count();
  expect(before, 'cart must have a line to remove').toBeGreaterThan(0);
  await page.locator('#itemData .delRow').first().click();
  await expect(lines).toHaveCount(before - 1);
}

export async function openOrderForEdit(page: Page, orderId: number): Promise<void> {
  await page.goto(`/pos_barcode.php?edit=${orderId}`);
  await expect(page.locator('#itemData .item-card-order')).not.toHaveCount(0, { timeout: 20_000 });
}

export async function saveTakeawayOrder(page: Page): Promise<{ orderId: number; proId: number }> {
  const saveResponse = page.waitForResponse((response) =>
    response.url().includes('api/pos/index.php')
    && response.request().method() === 'POST'
    && response.url().includes('route=orders.takeaway'),
  );
  await saveOrderOnly(page);
  const response = await saveResponse;
  const body = await response.json();
  expect(response.ok(), JSON.stringify(body)).toBeTruthy();
  expect(body.success).toBeTruthy();
  const orderId = Number(body.order_id);
  const proId = Number(body.pro_id);
  expect(orderId).toBeGreaterThan(0);
  expect(proId).toBeGreaterThan(0);
  return { orderId, proId };
}

export async function saveEditedOrder(page: Page): Promise<{ orderId: number; proId: number }> {
  const editSave = page.waitForResponse((response) =>
    response.url().includes('api/pos/index.php')
    && response.request().method() === 'POST'
    && (response.url().includes('route=orders.edit') || response.url().includes('route=orders.takeaway')),
  );
  await saveOrderOnly(page);
  const response = await editSave;
  const body = await response.json();
  expect(response.ok(), JSON.stringify(body)).toBeTruthy();
  expect(body.success).toBeTruthy();
  return {
    orderId: Number(body.order_id),
    proId: Number(body.pro_id),
  };
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

export async function toggleVirtualKeyboard(page: Page): Promise<void> {
  await page.locator('#posKeyboardToggleBtn').click();
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
  await Promise.race([
    page.waitForURL(/print\/receipt\.php/, { timeout: 30_000 }),
    expect(page.locator('#paymentModal')).toBeHidden({ timeout: 30_000 }),
  ]).catch(() => undefined);
}

export async function saveOrderOnly(page: Page): Promise<void> {
  const saveButton = page.locator('.pos-order-footer .pos-save-order-btn').first();
  await saveButton.scrollIntoViewIfNeeded();
  await saveButton.click({ force: true });
}

export function isCashierOrderSaveResponse(response: { url: () => string; request: () => { method: () => string } }): boolean {
  return isCashierOrderSaveUrl(response.url(), response.request().method());
}

export function isCashierOrderSaveUrl(url: string, method = 'POST'): boolean {
  return url.includes('api/pos/index.php')
    && method === 'POST'
    && (url.includes('route=orders.takeaway') || url.includes('route=orders.edit'));
}

export async function expectSaveButtonState(
  page: Page,
  state: 'empty' | 'dirty' | 'saving' | 'saved',
  label?: string,
): Promise<void> {
  const saveButton = page.locator('.pos-order-footer .pos-save-order-btn').first();
  await expect(saveButton).toHaveAttribute('data-pos-save-state', state);
  if (label) {
    await expect(saveButton).toContainText(label);
  }
  if (state === 'dirty') {
    await expect(saveButton).toBeEnabled();
  } else {
    await expect(saveButton).toBeDisabled();
  }
}

export async function saveTableOrderAndWait(page: Page): Promise<void> {
  let dialogMessage = '';
  page.once('dialog', async dialog => {
    dialogMessage = dialog.message();
    await dialog.accept();
  });

  const tableSave = page.waitForResponse((response) => {
    return response.url().includes('api/pos/index.php')
      && response.request().method() === 'POST'
      && response.url().includes('route=orders.table');
  }, { timeout: 30_000 });

  await saveOrderOnly(page);
  const tableResponse = await tableSave;
  const tableBody = await tableResponse.json().catch(() => ({}));

  expect(dialogMessage, `POS validation blocked table save: ${dialogMessage}`).toBe('');
  expect(tableResponse.ok(), JSON.stringify(tableBody)).toBeTruthy();
  expect(tableBody.success).toBeTruthy();
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

async function isTablesModalOpen(page: Page): Promise<boolean> {
  return page.evaluate(() => {
    const modal = document.getElementById('tablesModal');
    return !!(modal && modal.classList.contains('show'));
  });
}

export async function dismissOrderSuccessModal(page: Page): Promise<void> {
  await page.waitForFunction(() => {
    const hold = !!(window as Window & { POS_SUCCESS_HOLD?: boolean }).POS_SUCCESS_HOLD;
    const modal = document.getElementById('posOrderSuccessModal');
    if (!modal) {
      return !hold;
    }
    const visible = modal.classList.contains('show') || modal.getAttribute('aria-hidden') === 'false';
    return !visible && !hold;
  }, null, { timeout: 10_000 });

  await page.evaluate(() => {
    const successModal = document.getElementById('posOrderSuccessModal');
    if (successModal?.classList.contains('show') && window.bootstrap?.Modal) {
      const instance = window.bootstrap.Modal.getInstance(successModal);
      instance?.hide();
    }
    const openModal = document.querySelector('.modal.show');
    if (!openModal) {
      document.querySelectorAll('.modal-backdrop').forEach((backdrop) => backdrop.remove());
      document.body.classList.remove('modal-open');
      document.body.style.removeProperty('overflow');
      document.body.style.removeProperty('padding-right');
    }
  });
}

export async function openTablesModal(page: Page): Promise<void> {
  await dismissOrderSuccessModal(page);

  if (await isTablesModalOpen(page)) {
    return;
  }

  const tableModeTab = page.locator('.pos-mode-tab[data-age-target="age2"]').first();
  if (await tableModeTab.isVisible().catch(() => false)) {
    await tableModeTab.click();
    await page.waitForFunction(() => {
      const modal = document.getElementById('tablesModal');
      return !!(modal && modal.classList.contains('show'));
    }, null, { timeout: 10_000 });
    return;
  }

  const tablesButton = page.locator('[data-bs-target="#tablesModal"]').first();
  await expect(tablesButton).toBeVisible();
  await tablesButton.click({ force: true });
  await page.waitForFunction(() => {
    const modal = document.getElementById('tablesModal');
    return !!(modal && modal.classList.contains('show'));
  }, null, { timeout: 10_000 });
}

export async function selectFirstAvailableTable(page: Page): Promise<void> {
  await openTablesModal(page);

  const available = page.locator('.table-select-btn[data-table-case="0"]').first();
  await expect(available).toBeVisible({ timeout: 15_000 });
  await available.click();
  await page.waitForFunction(() => {
    const modal = document.getElementById('tablesModal');
    return !modal || !modal.classList.contains('show');
  }, null, { timeout: 10_000 }).catch(() => undefined);
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
