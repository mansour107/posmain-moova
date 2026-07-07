import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import { toggleVirtualKeyboard } from '../helpers/pos';

test.describe('cashier: virtual keyboard', () => {
  test('opens touch keyboard and types into unified search', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');

    const search = page.locator('#posUnifiedSearch');
    await expect(search).toBeVisible();

    await toggleVirtualKeyboard(page);
    const keyboard = page.locator('#posVirtualKeyboard');
    await expect(keyboard).toBeVisible();
    await expect(page.locator('#posKeyboardToggleBtn')).toHaveAttribute('aria-pressed', 'true');

    await page.locator('#posVirtualKeyboard .pos-vk-key[data-key="م"]').click();
    await page.locator('#posVirtualKeyboard .pos-vk-key[data-key="ك"]').click();
    await expect(search).toHaveValue('مك');

    await page.locator('#posVirtualKeyboard [data-action="clear"]').click();
    await expect(search).toHaveValue('');

    await page.locator('#posVirtualKeyboard [data-action="close"]').click();
    await expect(keyboard).toBeHidden();
    await expect(page.locator('#posKeyboardToggleBtn')).toHaveAttribute('aria-pressed', 'false');
  });
});
