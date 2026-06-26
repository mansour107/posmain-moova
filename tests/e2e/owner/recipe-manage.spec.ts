import { test, expect } from '@playwright/test';
import { loginAs, assertNoFatalText } from '../helpers/auth';

test.describe('owner: recipe management', () => {
  test('recipe_manage.php renders for admin', async ({ page }) => {
    await loginAs(page, 'admin');
    const response = await page.goto('/recipe_manage.php');
    expect(response?.status() ?? 0).toBeLessThan(500);

    const body = await page.content();
    assertNoFatalText(body);
    expect(body).toMatch(/وصفة|recipe/i);
  });
});
