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

  test('Moova bell/speaker stay put when toggling the panel', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');
    await page.goto('/pos_barcode.php');

    const frameLocator = page.locator('.pos-corner-menu #cofe-pos-widget');
    await expect(frameLocator).toBeVisible();

    const result = await page.evaluate(async () => {
      const frame = document.querySelector('.pos-corner-menu #cofe-pos-widget') as HTMLIFrameElement | null;
      if (!frame) {
        return { ok: false, reason: 'missing-frame' };
      }

      const waitForBell = () =>
        new Promise<HTMLButtonElement>((resolve, reject) => {
          let n = 0;
          const timer = window.setInterval(() => {
            n += 1;
            const bell = frame.contentDocument?.getElementById('pos-widget-bell') as HTMLButtonElement | null;
            if (bell) {
              window.clearInterval(timer);
              resolve(bell);
            }
            if (n > 60) {
              window.clearInterval(timer);
              reject(new Error('bell not ready'));
            }
          }, 100);
        });

      const bell = await waitForBell();
      const sound = frame.contentDocument?.getElementById('pos-widget-sound-toggle') as HTMLElement | null;
      if (!sound) {
        return { ok: false, reason: 'missing-sound' };
      }

      const pagePos = (el: HTMLElement) => {
        const local = el.getBoundingClientRect();
        const fr = frame.getBoundingClientRect();
        return { top: fr.top + local.top, left: fr.left + local.left };
      };

      const beforeBell = pagePos(bell);
      const beforeSound = pagePos(sound);
      bell.click();
      await new Promise((r) => window.setTimeout(r, 250));
      const openBell = pagePos(bell);
      const openSound = pagePos(sound);
      bell.click();
      await new Promise((r) => window.setTimeout(r, 250));
      const closedBell = pagePos(bell);

      const maxDrift = Math.max(
        Math.abs(openBell.top - beforeBell.top),
        Math.abs(openBell.left - beforeBell.left),
        Math.abs(openSound.top - beforeSound.top),
        Math.abs(openSound.left - beforeSound.left),
        Math.abs(closedBell.top - beforeBell.top),
        Math.abs(closedBell.left - beforeBell.left),
      );

      return { ok: maxDrift < 0.5, maxDrift, beforeBell, openBell, closedBell };
    });

    expect(result.ok, JSON.stringify(result)).toBeTruthy();
  });
});
