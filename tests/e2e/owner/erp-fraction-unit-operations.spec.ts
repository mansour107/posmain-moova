import { test, expect, type Page } from '@playwright/test';
import { loginAs, loginAndUnlockPos } from '../helpers/auth';
import {
  fillCreateItemForm,
  uniqueItemLabel,
  dbItemIdByBarcode,
  dbUnitFlags,
  queryLocalDb,
  ITEM_EDITOR_UNITS,
  saveItemClose,
} from '../helpers/item-editor';
import {
  addItemBySearchBarcode,
  saveTakeawayOrder,
  dismissOrderSuccessModal,
  clearCartFromHeader,
} from '../helpers/pos';

const label = uniqueItemLabel('FracUnit');
let itemId = 0;
let storeId = 0;

function dbNumber(sql: string): number {
  return Number(queryLocalDb(sql) || 0);
}

function dbStockQty(item: number): number {
  return dbNumber(`SELECT itmqty FROM myitems WHERE id=${item}`);
}

function dbLatestPosQtyOut(item: number): number {
  return dbNumber(
    `SELECT qty_out FROM fat_details WHERE item_id=${item} AND fat_tybe=9 AND COALESCE(isdeleted,0)=0 ORDER BY id DESC LIMIT 1`,
  );
}

function dbLatestPosUVal(item: number): number {
  return dbNumber(
    `SELECT u_val FROM fat_details WHERE item_id=${item} AND fat_tybe=9 AND COALESCE(isdeleted,0)=0 ORDER BY id DESC LIMIT 1`,
  );
}

function dbLedgerBalance(item: number, store: number): number {
  return dbNumber(
    `SELECT qty_on_hand FROM inventory_item_balances WHERE item_id=${item} AND store_id=${store} LIMIT 1`,
  );
}

async function setCartQty(page: Page, qty: string): Promise<void> {
  const input = page.locator('#itemData .quantityInput').first();
  await expect(input).toBeVisible({ timeout: 10_000 });
  await input.click({ clickCount: 3 });
  await input.fill(qty);
  await input.blur();
  await expect(input).toHaveValue(qty);
}

async function selectAdjustmentItem(page: Page, needle: string): Promise<void> {
  const search = page.locator('#inventoryAdjustmentItemSearch');
  await search.fill(needle);
  await page.locator('#inventoryAdjustmentItemResults .inventory-adjustment-option').first().click();
  await expect(page.locator('#inventoryAdjustmentItem')).not.toHaveValue('');
}

async function postInventoryAdjustment(
  page: Page,
  action: 'increase' | 'decrease',
  qty: string,
  unitId: string,
  reason: string,
): Promise<void> {
  await page.locator(`[data-adjustment-action="${action}"]`).click();
  await selectAdjustmentItem(page, label.barcode);
  const unitSelect = page.locator('#inventoryAdjustmentUnit');
  await expect(unitSelect.locator(`option[value="${unitId}"]`)).toHaveCount(1, { timeout: 10_000 });
  await unitSelect.selectOption(unitId);
  await page.locator('#inventoryAdjustmentQty').fill(qty);
  await page.locator('#inventoryAdjustmentReason').fill(reason);

  const response = page.waitForResponse(
    (resp) => resp.url().includes('ajax/inventory_adjustment.php') && resp.request().method() === 'POST',
    { timeout: 20_000 },
  );
  await page.locator('#postInventoryAdjustment').click();
  const saveResp = await response;
  const body = await saveResp.json();
  expect(body.success, body.message || 'adjustment failed').toBe(true);
  await page.waitForLoadState('networkidle');
}

test.describe.serial('owner: ERP fraction unit operations on local shop', () => {
  test('create item — 1 carton (storage) = 12 pieces (sell)', async ({ page }) => {
    await loginAs(page, 'admin');
    await fillCreateItemForm(page, {
      name: label.name,
      barcode: label.barcode,
      type: 'sellable',
      sell: { unitId: ITEM_EDITOR_UNITS.piece, price1: '6' },
      purchaseActive: true,
      purchase: {
        storageUnitId: ITEM_EDITOR_UNITS.carton,
        purchaseUnitId: ITEM_EDITOR_UNITS.carton,
        purchaseStorageFactor: '1',
        sellStorageFactor: '12',
        cost: '72',
      },
    });
    await saveItemClose(page);

    itemId = dbItemIdByBarcode(label.barcode);
    const flags = dbUnitFlags(itemId);
    expect(flags.length).toBeGreaterThanOrEqual(2);

    const sell = flags.find((row) => row.def_sale === 1);
    const stock = flags.find((row) => row.def_stock === 1);
    expect(sell?.unit_id).toBe(Number(ITEM_EDITOR_UNITS.piece));
    expect(stock?.unit_id).toBe(Number(ITEM_EDITOR_UNITS.carton));
    expect(Number(sell?.u_val)).toBeCloseTo(12, 2);

    await page.screenshot({ path: 'tests/e2e/screenshots/erp-frac-item-created.png', fullPage: true });
  });

  test('inventory increase +2 cartons', async ({ page }) => {
    test.skip(itemId < 1, 'item fixture missing');

    await loginAs(page, 'admin');
    await page.goto('/inventory_adjustments.php');
    await expect(page.locator('#postInventoryAdjustment')).toBeVisible();

    storeId = Number(await page.locator('#inventoryAdjustmentStore option').first().getAttribute('value'));
    expect(storeId).toBeGreaterThan(0);

    const beforeStock = dbStockQty(itemId);
    const beforeLedger = dbLedgerBalance(itemId, storeId);

    await postInventoryAdjustment(page, 'increase', '2', ITEM_EDITOR_UNITS.carton, 'E2E add 2 cartons');

    const afterStock = dbStockQty(itemId);
    const afterLedger = dbLedgerBalance(itemId, storeId);

    expect(afterStock - beforeStock).toBeCloseTo(2, 3);
    expect(afterLedger - beforeLedger).toBeCloseTo(2, 3);

    await page.screenshot({ path: 'tests/e2e/screenshots/erp-frac-inventory-increase.png', fullPage: true });
  });

  test('POS sell 12 pieces deducts 1 carton from stock', async ({ page }) => {
    test.skip(itemId < 1, 'item fixture missing');

    await loginAndUnlockPos(page, 'admin');
    const beforeStock = dbStockQty(itemId);

    const searchResponse = page.waitForResponse((resp) =>
      resp.url().includes('ajax/search_item.php') && resp.request().method() === 'POST',
    );
    await addItemBySearchBarcode(page, label.barcode);
    const searchBody = await (await searchResponse).json();
    expect(searchBody.success).toBeTruthy();
    const resolvedUVal = Number(searchBody.item.u_val);
    expect(resolvedUVal).toBeGreaterThan(0);
    expect(resolvedUVal).toBeLessThan(1);

    await expect(page.locator('#itemData .item-card-order')).toHaveCount(1, { timeout: 10_000 });
    await setCartQty(page, '12');

    const { orderId } = await saveTakeawayOrder(page);
    expect(orderId).toBeGreaterThan(0);

    const qtyOut = dbLatestPosQtyOut(itemId);
    const storedUVal = dbLatestPosUVal(itemId);
    const afterStock = dbStockQty(itemId);

    expect(qtyOut).toBeCloseTo(1, 3);
    expect(storedUVal).toBeCloseTo(resolvedUVal, 2);
    expect(beforeStock - afterStock).toBeCloseTo(1, 3);

    await page.screenshot({ path: 'tests/e2e/screenshots/erp-frac-pos-sell-12.png', fullPage: true });
    await dismissOrderSuccessModal(page);
    await clearCartFromHeader(page);
  });

  test('POS sell 7 pieces deducts fractional carton (7/12)', async ({ page }) => {
    test.skip(itemId < 1, 'item fixture missing');

    await loginAndUnlockPos(page, 'admin');
    const beforeStock = dbStockQty(itemId);

    await addItemBySearchBarcode(page, label.barcode);
    await expect(page.locator('#itemData .item-card-order')).toHaveCount(1, { timeout: 10_000 });
    await setCartQty(page, '7');

    await saveTakeawayOrder(page);

    const qtyOut = dbLatestPosQtyOut(itemId);
    const afterStock = dbStockQty(itemId);
    const expected = 7 / 12;

    expect(qtyOut).toBeCloseTo(expected, 4);
    expect(beforeStock - afterStock).toBeCloseTo(expected, 4);

    await page.screenshot({ path: 'tests/e2e/screenshots/erp-frac-pos-sell-7.png', fullPage: true });
    await dismissOrderSuccessModal(page);
  });

  test('inventory decrease 5 pieces subtracts 5/12 carton', async ({ page }) => {
    test.skip(itemId < 1 || storeId < 1, 'fixtures missing');

    await loginAs(page, 'admin');
    await page.goto('/inventory_adjustments.php');

    const beforeStock = dbStockQty(itemId);
    const beforeLedger = dbLedgerBalance(itemId, storeId);

    await postInventoryAdjustment(page, 'decrease', '5', ITEM_EDITOR_UNITS.piece, 'E2E subtract 5 pieces');

    const afterStock = dbStockQty(itemId);
    const afterLedger = dbLedgerBalance(itemId, storeId);
    const expected = 5 / 12;

    expect(beforeStock - afterStock).toBeCloseTo(expected, 4);
    expect(beforeLedger - afterLedger).toBeCloseTo(expected, 4);

    await page.screenshot({ path: 'tests/e2e/screenshots/erp-frac-inventory-decrease.png', fullPage: true });
  });
});
