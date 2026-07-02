import { test, expect } from '@playwright/test';
import { loginAs, assertNoFatalText } from '../helpers/auth';

test.describe('owner: item unit profile editor', () => {
  test.beforeEach(async ({ page }) => {
    await loginAs(page, 'admin');
    const response = await page.goto('/add_item.php');
    expect(response?.status() ?? 0).toBeLessThan(500);
    assertNoFatalText(await page.content());
  });

  test('sellable shows البيع section and requires sell price', async ({ page }) => {
    await expect(page.locator('#item-sell-section')).toBeVisible();
    await expect(page.locator('#item-purchase-section')).toBeVisible();
    await expect(page.locator('#sell_price1')).toBeVisible();
    await expect(page.locator('#sell-section-toggle-wrap')).toHaveClass(/d-none/);

    await page.fill('#iname', `E2E Sellable ${Date.now()}`);
    await page.fill('input[name="barcode"]', `E2E-S-${Date.now()}`);
    await page.fill('#sell_price1', '0');
    await page.locator('#item-main-form').evaluate((form: HTMLFormElement) => form.requestSubmit());
    page.once('dialog', async (dialog) => {
      expect(dialog.message()).toContain('سعر البيع');
      await dialog.dismiss();
    });
  });

  test('service hides شراء وتخزين', async ({ page }) => {
    await page.locator('.item-type-choice[data-item-type="service"]').click();
    await expect(page.locator('#item-purchase-section')).toHaveClass(/d-none/);
    await expect(page.locator('#sell_price1')).toBeVisible();
  });

  test('ingredient requires storage unit', async ({ page }) => {
    await page.locator('.item-type-choice[data-item-type="ingredient"]').click();
    await expect(page.locator('#storage_unit_id')).toBeVisible();
    await expect(page.locator('#sell-section-toggle-wrap')).not.toHaveClass(/d-none/);

    await page.fill('#iname', `E2E Ingredient ${Date.now()}`);
    await page.fill('input[name="barcode"]', `E2E-I-${Date.now()}`);
    await page.selectOption('#storage_unit_id', { index: 1 });
    await page.locator('#item-main-form').evaluate((form: HTMLFormElement) => form.requestSubmit());
    page.once('dialog', async (dialog) => {
      await dialog.dismiss();
    });
  });

  test('purchase section exposes cost when activated', async ({ page }) => {
    await page.locator('#purchase_section_checkbox').check();
    await expect(page.locator('#purchase_cost')).toBeVisible();
    await expect(page.locator('#purchase_unit_id')).toBeVisible();
  });
});
