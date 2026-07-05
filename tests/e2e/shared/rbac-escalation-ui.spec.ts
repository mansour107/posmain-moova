import { test, expect } from '@playwright/test';
import { loginAs, loginAndUnlockPos } from '../helpers/auth';

test('POS unlock screen is RTL', async ({ page }) => {
  await loginAs(page, 'cashier');
  await page.goto('/pos_barcode.php', { waitUntil: 'domcontentloaded' });
  const dir = await page.locator('html').getAttribute('dir');
  expect(dir).toBe('rtl');
});

test('unlocked POS exposes acting user and override CSRF', async ({ page }) => {
  await loginAndUnlockPos(page, 'cashier');
  await expect(page.locator('#posForm')).toBeVisible();
  await expect(page.locator('#posActingUserId')).toHaveCount(1);
  await expect(page.locator('meta[name="pos-override-csrf-token"]')).toHaveCount(1);
});

test('unlocked POS injects acting capabilities and limits', async ({ page }) => {
  await loginAndUnlockPos(page, 'cashier');
  const payload = await page.evaluate(() => ({
    caps: (window as unknown as { POSMAIN_CAPABILITIES?: Record<string, boolean> }).POSMAIN_CAPABILITIES,
    limits: (window as unknown as { POSMAIN_LIMITS?: Record<string, unknown> }).POSMAIN_LIMITS,
    can: typeof (window as unknown as { POSMAIN?: { can?: (k: string) => boolean } }).POSMAIN?.can,
  }));
  expect(payload.caps).toBeTruthy();
  expect(payload.limits).toBeTruthy();
  expect(payload.can).toBe('function');
});

test('pos session status exposes capabilities JSON', async ({ page }) => {
  await loginAs(page, 'admin');
  const payload = await page.evaluate(async () => {
    const response = await fetch('/pos_session_status.php', { credentials: 'same-origin' });
    return response.json();
  });
  expect(payload.capabilities).toBeTruthy();
});

test('pin available endpoint answers pin uniqueness', async ({ page }) => {
  await loginAs(page, 'admin');
  const payload = await page.evaluate(async () => {
    const response = await fetch('/ajax/pin_available.php?pin=9876', { credentials: 'same-origin' });
    return response.json();
  });
  expect(Object.keys(payload).sort()).toEqual(['available']);
  expect(typeof payload.available).toBe('boolean');
});

test('override auth rejects missing CSRF', async ({ page }) => {
  await loginAndUnlockPos(page, 'cashier');
  const payload = await page.evaluate(async () => {
    const response = await fetch('/ajax/pos_override_auth.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'manager_pin=1234&permission_key=pos.discount.manual_pct.limit',
    });
    return { status: response.status, body: await response.json().catch(() => ({})) };
  });
  expect(payload.status).toBeGreaterThanOrEqual(400);
});

test('users page shows PIN status column for admin', async ({ page }) => {
  await loginAs(page, 'admin');
  await page.goto('/users.php', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('th', { hasText: 'PIN' })).toBeVisible();
});

test('role permissions page shows limit matrix for preset role', async ({ page }) => {
  await loginAs(page, 'admin');
  await page.goto('/role_permissions.php?id=29', { waitUntil: 'domcontentloaded' });
  if (page.url().includes('myroles.php')) {
    await page.goto('/myroles.php', { waitUntil: 'domcontentloaded' });
    const permissionsLink = page.locator('a[href*="role_permissions.php"]').first();
    await permissionsLink.click();
  }
  await expect(page.locator('code', { hasText: 'pos.discount.apply' })).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('input[name^="limit_value"]').first()).toBeVisible();
  const restoreForm = page.locator('form[action*="do_restore_role_preset"]');
  if (await restoreForm.count()) {
    await expect(restoreForm).toBeVisible();
  }
});
