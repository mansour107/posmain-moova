import { test, expect } from '@playwright/test';
import { assertNoFatalText } from '../helpers/auth';

test.describe('shared: auth redirects', () => {
  test('POS requires authentication', async ({ page }) => {
    await page.goto('/pos_barcode.php');
    const body = await page.content();
    assertNoFatalText(body);

    const onPosGate = await page.locator('input[name="pos_barcode"]').isVisible().catch(() => false);
    const onLogin = page.url().includes('index.php');
    expect(onPosGate || onLogin).toBeTruthy();
  });

  test('recipe management redirects or denies anonymous users', async ({ page }) => {
    const response = await page.goto('/recipe_manage.php');
    const body = await page.content();
    assertNoFatalText(body);
    expect(response?.status() ?? 0).toBeLessThan(500);
    const protectedSurface =
      page.url().includes('index.php') ||
      /PERMISSION_DENIED|غير مصرح|login|دخول/i.test(body);
    expect(protectedSurface).toBeTruthy();
  });
});
