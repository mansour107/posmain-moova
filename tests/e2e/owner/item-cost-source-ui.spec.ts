import { test, expect } from '@playwright/test';
import { loginAs, assertNoFatalText } from '../helpers/auth';
import {
  fillCreateItemForm,
  ITEM_EDITOR_UNITS,
  openAddItemEditor,
  saveItemClose,
  setPurchaseSectionActive,
  uniqueItemLabel,
} from '../helpers/item-editor';

const screenshotDir = 'tests/e2e/screenshots';

test.describe('owner: item cost source UX', () => {
  test.beforeEach(async ({ page }) => {
    await loginAs(page, 'admin');
    const response = await page.goto('/add_item.php');
    expect(response?.status() ?? 0).toBeLessThan(500);
    assertNoFatalText(await page.content());
  });

  test('new item defaults to manual cost source with recipe disabled', async ({ page }) => {
    await expect(page.locator('#cost-source-direct-choice')).toHaveClass(/is-active/);
    await expect(page.locator('#cost-source-recipe-choice input')).toBeDisabled();
    await expect(page.locator('#cost-source-recipe-desc')).toContainText('أضف وصفة لاحقاً');
    await expect(page.locator('#cost_per_unit_value')).toBeEditable();

    await page.screenshot({ path: `${screenshotDir}/cost-source-new-item-manual.png`, fullPage: true });
  });

  test('purchase cost source enables after purchase section is active', async ({ page }) => {
    const { name, barcode } = uniqueItemLabel('CostPurchase');
    await page.locator('#iname').fill(name);
    await page.locator('input[name="barcode"]').fill(barcode);
    await page.locator('#sell_price1').fill('25');
    await page.locator('#cost_per_unit_value').fill('9');

    await setPurchaseSectionActive(page, true);
    await page.locator('#purchase_cost').fill('120');
    await expect(page.locator('#cost-source-purchase-choice input')).toBeEnabled();

    await page.locator('#cost-source-purchase-choice').click();
    await expect(page.locator('#cost-source-purchase-choice')).toHaveClass(/is-active/);
    await expect(page.locator('#cost_per_unit_value')).not.toBeEditable();

    await page.screenshot({ path: `${screenshotDir}/cost-source-purchase-mode.png`, fullPage: true });

    await page.locator('button[name="save_intent"][value="close"]').click();
    await page.waitForURL(/myitems\.php/, { timeout: 45_000 });
  });

  test('profit margin is markup on cost not margin on price', async ({ page }) => {
    await page.locator('#sell_price1').fill('100');
    await page.locator('#cost_per_unit_value').fill('50');
    await expect(page.locator('#sell_profit_margin')).toHaveText('100%');
    await expect(page.locator('#summaryProfitMargin')).toHaveText('100%');
  });

  test('manual override stays editable when switching back from purchase', async ({ page }) => {
    await page.locator('#sell_price1').fill('20');
    await setPurchaseSectionActive(page, true);
    await page.locator('#purchase_cost').fill('50');
    await page.locator('#cost-source-purchase-choice').click();
    await page.locator('#cost-source-direct-choice').click();
    await expect(page.locator('#cost_per_unit_value')).toBeEditable();
    await page.locator('#cost_per_unit_value').fill('7.5');
    await expect(page.locator('#sell_profit_margin')).not.toHaveText('—');

    await page.screenshot({ path: `${screenshotDir}/cost-source-manual-override.png`, fullPage: true });
  });

  test('sellable create flow keeps sidebar sanity check in sync', async ({ page }) => {
    const { name, barcode } = uniqueItemLabel('CostSidebar');
    await fillCreateItemForm(page, {
      name,
      barcode,
      type: 'sellable',
      sell: { unitId: ITEM_EDITOR_UNITS.piece, price1: '40' },
    });
    await page.locator('#cost_per_unit_value').fill('12');
    await expect(page.locator('#summarySellPrice')).toHaveText('40');
    await expect(page.locator('#summaryCostPerSellUnit')).toHaveText('12');
    await expect(page.locator('#summaryProfitMargin')).toContainText('%');

    await page.screenshot({ path: `${screenshotDir}/cost-source-sidebar-sanity.png`, fullPage: true });
    await saveItemClose(page);
  });
});
