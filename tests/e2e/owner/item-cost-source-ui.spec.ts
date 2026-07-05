import { test, expect } from '@playwright/test';
import { loginAs, assertNoFatalText } from '../helpers/auth';
import {
  fillCreateItemForm,
  openAddItemEditor,
  saveItem,
  uniqueItemLabel,
} from '../helpers/item-editor';

const screenshotDir = 'tests/e2e/screenshots';

test.describe('owner: item pricing UX', () => {
  test.beforeEach(async ({ page }) => {
    await loginAs(page, 'admin');
    const response = await page.goto('/add_item.php');
    expect(response?.status() ?? 0).toBeLessThan(500);
    assertNoFatalText(await page.content());
  });

  test('new item exposes sell price and direct cost fields', async ({ page }) => {
    await expect(page.locator('#item-pricing-section')).toBeVisible();
    await expect(page.locator('#sell_price1')).toBeVisible();
    await expect(page.locator('#direct_cost_price')).toBeVisible();
    await expect(page.locator('#cost_source')).toHaveValue('direct');
    await expect(page.locator('#purchase_active')).toHaveValue('0');

    await page.screenshot({ path: `${screenshotDir}/pricing-new-item-fields.png`, fullPage: true });
  });

  test('profit margin is markup on cost not margin on price', async ({ page }) => {
    await page.locator('#sell_price1').fill('100');
    await page.locator('#direct_cost_price').fill('50');
    await expect(page.locator('#sell_profit_margin')).toHaveText('100%');
    await expect(page.locator('#summaryProfitMargin')).toHaveText('100%');
  });

  test('sellable create flow keeps sidebar sanity check in sync', async ({ page }) => {
    const { name, barcode } = uniqueItemLabel('PricingSidebar');
    await fillCreateItemForm(page, {
      name,
      barcode,
      type: 'sellable',
      sell: { price1: '40', cost: '12' },
    });
    await expect(page.locator('#summarySellPrice')).toHaveText('40');
    await expect(page.locator('#summaryCost')).toHaveText('12');
    await expect(page.locator('#summaryProfitMargin')).toContainText('%');

    await page.screenshot({ path: `${screenshotDir}/pricing-sidebar-sanity.png`, fullPage: true });
    await saveItem(page);
  });

  test('pricing panel hidden for ingredient without sell', async ({ page }) => {
    await openAddItemEditor(page);
    await page.locator('.item-type-choice[data-item-type="ingredient"]').click();
    await expect(page.locator('#item-pricing-body')).toHaveClass(/d-none/);
  });
});
