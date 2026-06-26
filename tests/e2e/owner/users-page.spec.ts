import { test, expect } from '@playwright/test';
import { loginAs, assertNoFatalText } from '../helpers/auth';

test.describe('owner: users page', () => {
  test('users management page renders for admin', async ({ page }) => {
    await loginAs(page, 'admin');
    const response = await page.goto('/users.php');
    expect(response?.status() ?? 0).toBeLessThan(500);

    const body = await page.content();
    assertNoFatalText(body);
    await expect(page.getByText(/المستخدمين|users/i).first()).toBeVisible();
  });
});
