import { test, expect, type Page } from '@playwright/test';
import { loginAndUnlockPos, unlockPos } from '../helpers/auth';
import { fillCloseShiftForm } from '../helpers/shift';

// Full matrix for the "back button revives a closed shift" bug.
// Covers: standard close + Z-report close, browser back navigation, direct
// server re-close/order attempts, the session-status endpoint, the z_report
// gate, the supermarket page parity, and re-unlock recovery.

async function readCsrf(page: Page): Promise<string> {
  // Any POS page renders csrf tokens as hidden inputs named csrf_token.
  const token = await page
    .locator('input[name="csrf_token"]')
    .first()
    .getAttribute('value')
    .catch(() => null);
  return token || '';
}

async function openCloseShiftModal(page: Page): Promise<void> {
  const shiftButton = page
    .locator('[data-bs-target="#closeShiftModal"], button[title="إغلاق الشيفت"]')
    .first();
  await expect(shiftButton).toBeVisible({ timeout: 15_000 });
  await shiftButton.click();
  await expect(page.locator('#closeShiftModal')).toBeVisible({ timeout: 10_000 });
}

async function submitCloseShift(page: Page): Promise<void> {
  await fillCloseShiftForm(page);
  await page.waitForURL(/pos_barcode\.php/, { timeout: 20_000 });
  await expect(page.locator('#shiftCloseResultModal')).toBeVisible({ timeout: 10_000 });
  await expect(page.locator('#pinPadSection, .unlock-shell')).toHaveCount(0);
  await expect(page.locator('#shiftCloseResultTimerBar')).toBeVisible();
  await Promise.all([
    page.waitForURL(/do_logout\.php|index\.php|login\.php/, { timeout: 15_000, waitUntil: 'commit' }),
    page.locator('#shiftCloseResultDismiss').click(),
  ]);
}

async function closeShiftViaUi(page: Page): Promise<void> {
  await openCloseShiftModal(page);
  await submitCloseShift(page);
}

test.describe('manager: shift close back-navigation hardening (full matrix)', () => {
  test('session-status endpoint flips open -> closed -> open across the lifecycle', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');

    // Open shift -> authorized.
    let status = await page.evaluate(async () => {
      const r = await fetch('pos_session_status.php', { cache: 'no-store' });
      return r.json();
    });
    expect(status.authenticated).toBe(true);
    expect(status.shift_open).toBe(true);

    await closeShiftViaUi(page);

    // Closed shift -> not authorized (server-authoritative, no cache reliance).
    status = await page.evaluate(async () => {
      const r = await fetch('pos_session_status.php', { cache: 'no-store' });
      return r.json();
    });
    expect(status.authenticated).toBe(false);
    expect(status.shift_open).toBe(false);

    // Re-unlock -> authorized again.
    await unlockPos(page, 'manager');
    status = await page.evaluate(async () => {
      const r = await fetch('pos_session_status.php', { cache: 'no-store' });
      return r.json();
    });
    expect(status.authenticated).toBe(true);
    expect(status.shift_open).toBe(true);
  });

  test('browser back after close never leaves an armed selling surface', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');
    await closeShiftViaUi(page);

    await page.goBack();
    await page.waitForLoadState('networkidle');

    // The guard either redirected to the barcode/logout screen, or the server
    // re-rendered the lock screen. In no case may an interactive #posForm remain.
    const posForm = page.locator('#posForm');
    await expect(posForm).toHaveCount(0, { timeout: 10_000 });
    await expect(page.locator('input[name="pos_barcode"], #posUnlockPinPad').first()).toBeVisible({ timeout: 10_000 });
  });

  test('server rejects a re-close of an already-closed shift (close_shift.php)', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');
    await closeShiftViaUi(page);

    // Direct re-POST in the same (now closed) session must not create a new close.
    const res = await page.request.post('/close_shift.php', {
      form: { cash: '0', fund_after: '0', expenses: '0' },
      maxRedirects: 0,
      failOnStatusCode: false,
    });
    // Blocked either by require_pos_authenticated (403) or the closed-shift guard (redirect out).
    expect([302, 303, 401, 403]).toContain(res.status());
    if (res.status() >= 300 && res.status() < 400) {
      expect(res.headers()['location'] || '').toMatch(/closed_sessions|pos_barcode|logout/);
    }
  });

  test('server rejects a re-close via the Z-report endpoint (do_close_shift_z.php)', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');
    await closeShiftViaUi(page);

    const res = await page.request.post('/do_close_shift_z.php', {
      form: { sys_total_sales: '0', sys_total_cash: '0', sys_total_visa: '0', sys_expenses: '0', actual_cash: '0', actual_visa: '0' },
      maxRedirects: 0,
      failOnStatusCode: false,
    });
    expect([302, 303, 401, 403]).toContain(res.status());
    if (res.status() >= 300 && res.status() < 400) {
      expect(res.headers()['location'] || '').toMatch(/closed_sessions|pos_barcode|logout/);
    }
  });

  test('z_report.php after close redirects away instead of re-showing the close form', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');
    await closeShiftViaUi(page);

    const res = await page.request.get('/z_report.php', { maxRedirects: 0, failOnStatusCode: false });
    expect([302, 303, 401, 403]).toContain(res.status());
    if (res.status() >= 300 && res.status() < 400) {
      expect(res.headers()['location'] || '').toMatch(/closed_sessions|pos_barcode|logout/);
    }
  });

  test('order creation is refused server-side after close (doadd_invoice.php)', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');
    await closeShiftViaUi(page);

    const res = await page.request.post('/do/doadd_invoice.php', {
      form: { pro_tybe: '9' },
      maxRedirects: 0,
      failOnStatusCode: false,
    });
    expect([302, 303, 401, 403]).toContain(res.status());
  });

  test('supermarket POS page also shows the lock screen after close', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');
    await closeShiftViaUi(page);

    await page.goto('/pos_supermarket.php');
    await expect(page.locator('#posForm')).toHaveCount(0, { timeout: 10_000 });
    await expect(page.locator('input[name="pos_barcode"], #posUnlockPinPad').first()).toBeVisible({ timeout: 10_000 });
  });

  test('close then Z-report response carries no-store headers', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');
    // While still open, both close endpoints must advertise no-store so the
    // rendered response is never cached for a later back-navigation.
    const csrf = await readCsrf(page);
    const zres = await page.request.post('/do_close_shift_z.php', {
      form: {
        csrf_token: csrf,
        sys_total_sales: '0', sys_total_cash: '0', sys_total_visa: '0',
        sys_expenses: '0', actual_cash: '0', actual_visa: '0',
      },
      maxRedirects: 0,
      failOnStatusCode: false,
    });
    expect((zres.headers()['cache-control'] || '')).toMatch(/no-store/);
  });

  test('re-unlock after close restores a working POS', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');
    await closeShiftViaUi(page);

    await unlockPos(page, 'manager');
    await expect(page.locator('#posForm')).toBeVisible({ timeout: 20_000 });
  });
});
