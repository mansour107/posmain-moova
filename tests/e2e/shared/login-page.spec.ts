import { test, expect } from '@playwright/test';
import { assertNoFatalText } from '../helpers/auth';

test.describe('shared: login page', () => {
  test('renders unified login form without fatal output', async ({ page }) => {
    const response = await page.goto('/index.php');
    expect(response?.ok()).toBeTruthy();

    const body = await page.content();
    assertNoFatalText(body);
    await expect(page.locator('#uname')).toBeVisible();
    await expect(page.locator('#password')).toBeVisible();
    await expect(page.getByRole('button', { name: /تسجيل الدخول/ })).toBeVisible();
  });
});
