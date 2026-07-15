import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';
import { loginAs, gotoAfterLogin, assertNoFatalText } from '../helpers/auth';

test.describe('owner: sales.php session keepalive', () => {
  test('admin stays logged in on sales buy and sale', async ({ page }) => {
    await loginAs(page, 'admin');
    await gotoAfterLogin(page, '/dashboard.php');
    await expect(page).toHaveURL(/dashboard\.php/);

    for (const path of ['/sales.php?q=buy', '/sales.php?q=sale'] as const) {
      await page.goto(path, { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(2000);
      const url = page.url();
      const body = await page.content();
      assertNoFatalText(body);
      expect(url, `unexpected logout on ${path}`).not.toMatch(/index\.php/);
      expect(body).toMatch(/فاتورة مبيعات|فاتورة مشتريات/);
    }

    await page.goto('/dashboard.php', { waitUntil: 'domcontentloaded' });
    expect(page.url()).toMatch(/dashboard\.php/);
  });

  test('stale heartbeat does not logout on sales document navigation', async ({ page, context }) => {
    await loginAs(page, 'admin');
    await gotoAfterLogin(page, '/dashboard.php');
    await expect(page).toHaveURL(/dashboard\.php/);

    const cookies = await context.cookies();
    const sessionCookie = cookies.find((c) => c.name === 'PHPSESSID');
    expect(sessionCookie?.value).toBeTruthy();

    // Stop client timers so they cannot refresh the stamp while we age it.
    await page.evaluate(() => {
      const highest = window.setTimeout(() => {}, 0);
      for (let i = 0; i <= highest; i++) {
        window.clearTimeout(i);
        window.clearInterval(i);
      }
    });

    const sid = sessionCookie!.value.replace(/[^a-zA-Z0-9,-]/g, '');
    execSync(
      `docker exec posmain-php php -r ` +
        `'session_id(${JSON.stringify(sid)}); session_start(); ` +
        `$_SESSION["posmain_heartbeat_last_at"]=time()-180; ` +
        `$_SESSION["posmain_session_last_seen_at"]=time()-120; ` +
        `session_write_close(); echo "aged";'`,
      { stdio: ['ignore', 'pipe', 'pipe'] }
    );

    await page.goto('/sales.php?q=buy', { waitUntil: 'domcontentloaded' });
    await expect(page, 'stale heartbeat must not bounce document nav to login').not.toHaveURL(/index\.php/);
    await expect(page.locator('body')).toContainText(/فاتورة مبيعات|فاتورة مشتريات/);
  });
});
