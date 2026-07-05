import { test, expect } from '@playwright/test';
import { loginAs, assertNoFatalText } from '../helpers/auth';

test.describe('owner: item unit profile editor', () => {
  test.beforeEach(async ({ page }) => {
    await loginAs(page, 'admin');
    const response = await page.goto('/add_item.php');
    expect(response?.status() ?? 0).toBeLessThan(500);
    assertNoFatalText(await page.content());
  });

  test('sellable shows pricing section and requires sell price', async ({ page }) => {
    await expect(page.locator('#item-pricing-section')).toBeVisible();
    await expect(page.locator('#sell_price1')).toBeVisible();
    await expect(page.locator('#direct_cost_price')).toBeVisible();

    await page.fill('#iname', `E2E Sellable ${Date.now()}`);
    await page.fill('input[name="barcode"]', `E2E-S-${Date.now()}`);
    await page.fill('#sell_price1', '0');
    await page.locator('#item-main-form').evaluate((form: HTMLFormElement) => form.requestSubmit());
    page.once('dialog', async (dialog) => {
      expect(dialog.message()).toContain('سعر البيع');
      await dialog.dismiss();
    });
  });

  test('service keeps pricing visible', async ({ page }) => {
    await page.locator('.item-type-choice[data-item-type="service"]').click();
    await expect(page.locator('#item-pricing-section')).toBeVisible();
    await expect(page.locator('#sell_price1')).toBeVisible();
  });

  test('ingredient hides pricing when sell is off', async ({ page }) => {
    await page.locator('.item-type-choice[data-item-type="ingredient"]').click();
    await expect(page.locator('#item-pricing-body')).toHaveClass(/d-none/);

    await page.fill('#iname', `E2E Ingredient ${Date.now()}`);
    await page.fill('input[name="barcode"]', `E2E-I-${Date.now()}`);
    await page.locator('#item-main-form').evaluate((form: HTMLFormElement) => form.requestSubmit());
    await page.waitForURL(/add_item\.php/, { timeout: 45_000 });
  });

  test.skip('purchase section — removed with simplified pricing UI', async () => {});

  test.skip('inline unit picker — removed with simplified pricing UI', async () => {});

  test.skip('swap conversion direction — removed with simplified pricing UI', async () => {});

  test.skip('unit combobox reopen — removed with simplified pricing UI', async () => {});
});
