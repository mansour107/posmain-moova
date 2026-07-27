import { test, expect } from '@playwright/test';
import { loginAs } from '../helpers/auth';

/**
 * Real-owner simulation for the inventory stock levels page.
 *
 * This is not a "page returns 200" smoke. It simulates an owner actually
 * opening the page, reading the current levels table, searching for an item,
 * loading an existing row for edit, changing the reorder point, saving via the
 * real AJAX endpoint, and confirming the change persisted after reload.
 *
 * Run locally:
 *   npx playwright test --project=owner inventory-real-owner.spec.ts
 */

test.describe('owner: inventory stock levels — real owner journey', () => {
  test('owner opens page, reads levels, edits a row, saves, and sees it persist', async ({ page }) => {
    await loginAs(page, 'admin');

    const response = await page.goto('/inventory_stock_levels.php');
    expect(response?.status() ?? 0).toBeLessThan(500);

    const body = await page.content();
    expect(body, 'page must not fatal').not.toMatch(/fatal error|SQL syntax|mysqli_|uncaught exception/i);
    await expect(page.locator('h1.inventory-stock-level-title, .inventory-stock-level-title'))
      .toContainText(/مستويات المخزون|stock/i);

    // Owner sees the setup form (store / item / min / reorder / par / max / safety).
    await expect(page.locator('#inventoryStockLevelStore')).toBeVisible();
    await expect(page.locator('#inventoryStockLevelItem')).toBeVisible();
    await expect(page.locator('#inventoryStockLevelMinimum')).toBeVisible();
    await expect(page.locator('#inventoryStockLevelReorder')).toBeVisible();
    await expect(page.locator('#saveInventoryStockLevel')).toBeVisible();

    // Owner sees the current levels table (may be empty on a fresh shop, header still present).
    await expect(page.locator('table.inventory-stock-level-table thead')).toBeVisible();

    const firstLoadButton = page.locator('[data-stock-level-load]').first();
    const hasExistingRow = (await firstLoadButton.count()) > 0;

    if (hasExistingRow) {
      // Real owner flow: click "load for edit" on the first row, change reorder, save.
      const row = page.locator('table.inventory-stock-level-table tbody tr').first();
      const rowItemId = await row.locator('[data-stock-level-load]').first().getAttribute('data-item-id');
      const rowStoreId = await row.locator('[data-stock-level-load]').first().getAttribute('data-store-id');
      expect(rowItemId, 'row must expose item id for edit').toBeTruthy();
      expect(rowStoreId, 'row must expose store id for edit').toBeTruthy();

      await firstLoadButton.click();

      // Loading a row should populate store + item selects.
      await expect(page.locator('#inventoryStockLevelStore')).toHaveValue(String(rowStoreId));
      await expect(page.locator('#inventoryStockLevelItem')).toHaveValue(String(rowItemId));

      const reorderInput = page.locator('#inventoryStockLevelReorder');
      const saveButton = page.locator('#saveInventoryStockLevel');

      // Capture the loaded reorder value, then change it to a distinct value.
      const beforeText = await reorderInput.inputValue();
      const before = parseFloat(beforeText || '0');
      const after = (before + 5).toFixed(3);

      await reorderInput.fill(String(after));

      const saveRequest = page.waitForResponse(
        resp => resp.url().includes('ajax/inventory_stock_level_save.php') && resp.request().method() === 'POST',
        { timeout: 15_000 },
      );
      const reload = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
      await saveButton.click();
      const saveResp = await saveRequest;
      expect(saveResp.status(), 'save AJAX should not 5xx').toBeLessThan(500);

      const saveJson = await saveResp.json().catch(() => null);
      expect(saveJson, 'save endpoint must return JSON').toBeTruthy();
      expect(saveJson?.success, `save should report success: ${saveJson?.message ?? ''}`).toBe(true);

      // Page reloads after save (window.location.reload in the handler).
      await reload;

      // Confirm the saved reorder level is now visible in the table row.
      const savedRow = page.locator('table.inventory-stock-level-table tbody tr').first();
      await expect(savedRow).toContainText(String(after));
    } else {
      // Fresh shop with no levels yet: owner must still be able to pick store+item and submit.
      // We select the first available store and item, set a reorder value, and attempt save.
      await page.locator('#inventoryStockLevelStore').selectOption({ index: 0 });
      const itemSelect = page.locator('#inventoryStockLevelItem');
      const itemOptions = await itemSelect.locator('option').count();
      test.skip(itemOptions <= 1, 'no stock items seeded for inventory level save');
      await itemSelect.selectOption({ index: 1 });

      await page.locator('#inventoryStockLevelReorder').fill('7.000');
      const saveRequest = page.waitForResponse(
        resp => resp.url().includes('ajax/inventory_stock_level_save.php') && resp.request().method() === 'POST',
        { timeout: 15_000 },
      );
      await page.locator('#saveInventoryStockLevel').click();
      const saveResp = await saveRequest;
      const saveJson = await saveResp.json().catch(() => null);
      expect(saveJson?.success, `save should succeed on fresh level: ${saveJson?.message ?? ''}`).toBe(true);
    }

    // Final guard: no fatal/SQL leakage anywhere in the final DOM.
    const finalBody = await page.content();
    expect(finalBody).not.toMatch(/fatal error|SQL syntax|mysqli_|uncaught exception/i);
  });

  test('owner CSV template download works and returns CSV', async ({ page, request }) => {
    await loginAs(page, 'admin');

    const cookies = await page.context().cookies();
    const cookieHeader = cookies.map(c => `${c.name}=${c.value}`).join('; ');

    const resp = await request.get('/inventory_stock_levels.php?stock_level_export=template', {
      headers: { Cookie: cookieHeader },
    });
    // Template export is admin-only (system.tools.run). Admin should get 200 + CSV.
    expect(resp.status(), 'CSV template export should be 200 for admin').toBe(200);
    const text = await resp.text();
    // CSV template should mention the column headers used by the import textarea placeholder.
    expect(text).toMatch(/store_id|item_id|minimum_level/i);
  });
});
