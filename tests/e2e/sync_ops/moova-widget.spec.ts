import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';

test.describe('sync_ops: Moova widget surface', () => {
  test('POS loads Moova widget bootstrap without fatal errors', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
    const body = await page.content();
    expect(body).not.toMatch(/fatal error|SQL syntax/i);

    const widgetMarker = /cofe_widget|moova|Moova/i.test(body);
    expect(widgetMarker).toBeTruthy();
  });
});
