import { test, expect } from '@playwright/test';
import {
  enterManagerOverridePin,
  loginAndUnlockPos,
  logoutIfNeeded,
} from '../helpers/auth';
import {
  prepareCleanShift,
  skipUnlessHandoverEnabled,
  startFreshHandoverShift,
} from '../helpers/handover';

test.describe('temporary manager shift override', () => {
  test('cashier shift blocks manager; join path then end keeps shift open', async ({ page }, testInfo) => {
    prepareCleanShift();
    await startFreshHandoverShift(page, 'cashier', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    await logoutIfNeeded(page);
    await loginAndUnlockPos(page, 'manager');

    const recovery = page.locator('#posShiftRecoveryOverlay');
    await expect(recovery).toBeVisible({ timeout: 20_000 });
    await expect(recovery).toContainText(/الصندوق مشغول|درج مفتوح/);
    await expect(page.getByTestId('pos-choice-join')).toBeVisible();
    await expect(page.locator('#posOverrideManagerPin')).toHaveCount(0);
    await expect(page.locator('#posShiftRecoveryLeave')).toBeVisible();

    await page.getByTestId('pos-choice-join').click();
    await expect(page.getByTestId('pos-start-override')).toBeVisible();
    await expect(page.getByTestId('pos-start-override')).toContainText(/متابعة وإدخال رمز المدير/);

    await page.locator('#posOverrideReason').fill('مساعدة الكاشير أثناء الاستراحة');

    const startResp = page.waitForResponse(
      (response) => response.url().includes('do_start_drawer_override.php')
        && response.request().method() === 'POST',
      { timeout: 20_000 },
    );
    await page.getByTestId('pos-start-override').click();
    await enterManagerOverridePin(page, 'manager');
    const startBody = await (await startResp).json().catch(() => null) as { success?: boolean; error?: string } | null;
    expect(startBody?.success, JSON.stringify(startBody)).toBeTruthy();

    await expect(page.getByTestId('pos-override-banner')).toBeVisible({ timeout: 20_000 });
    await expect(page.locator('#posShiftRecoveryOverlay')).toBeHidden({ timeout: 10_000 });
    await expect(page.locator('#pshOpenOverlay')).toBeHidden();

    // Banner must not steal viewport height from pay/save or cover corner controls.
    await expect(page.locator('body')).toHaveClass(/pos-override-active/);
    const payBtn = page.locator('.pos-pay-order-btn').first();
    await expect(payBtn).toBeVisible();
    const geometry = await page.evaluate(() => {
      const banner = document.getElementById('posOverrideBanner');
      const pay = document.querySelector('.pos-pay-order-btn');
      const corner = document.querySelector('.pos-corner-menu');
      const shell = document.querySelector('.pos-shell');
      const vh = window.innerHeight;
      const bannerRect = banner ? banner.getBoundingClientRect() : null;
      const payRect = pay ? pay.getBoundingClientRect() : null;
      const cornerRect = corner ? corner.getBoundingClientRect() : null;
      const shellRect = shell ? shell.getBoundingClientRect() : null;
      return {
        vh,
        bannerBottom: bannerRect ? bannerRect.bottom : null,
        payBottom: payRect ? payRect.bottom : null,
        payVisible: !!(payRect && payRect.top < vh && payRect.bottom > 0),
        cornerBelowBanner: !!(bannerRect && cornerRect && cornerRect.top >= bannerRect.bottom - 1),
        shellFits: !!(shellRect && shellRect.bottom <= vh + 1),
      };
    });
    expect(geometry.payVisible, JSON.stringify(geometry)).toBeTruthy();
    expect(geometry.shellFits, JSON.stringify(geometry)).toBeTruthy();
    expect(geometry.cornerBelowBanner, JSON.stringify(geometry)).toBeTruthy();
    expect(geometry.payBottom ?? 0, JSON.stringify(geometry)).toBeLessThanOrEqual(geometry.vh + 1);

    const endResp = page.waitForResponse(
      (response) => response.url().includes('do_end_drawer_override.php')
        && response.request().method() === 'POST',
      { timeout: 20_000 },
    );
    await page.getByTestId('pos-end-override').click();
    await expect(page.getByTestId('pos-end-override-modal')).toBeVisible({ timeout: 10_000 });
    await page.getByTestId('pos-end-override-confirm').click();
    const endBody = await (await endResp).json().catch(() => null) as { success?: boolean; error?: string } | null;
    expect(endBody?.success, JSON.stringify(endBody)).toBeTruthy();

    await page.waitForURL(/pos_barcode\.php/, { timeout: 20_000, waitUntil: 'commit' });
    expect(page.url()).not.toMatch(/index\.php|logout=1/);

    await logoutIfNeeded(page);
    await loginAndUnlockPos(page, 'cashier');
    await expect
      .poll(async () => {
        const formVisible = await page.locator('#posForm').isVisible().catch(() => false);
        const recoveryVisible = await page.locator('#posShiftRecoveryOverlay').isVisible().catch(() => false);
        return formVisible || recoveryVisible;
      }, { timeout: 20_000 })
      .toBeTruthy();
  });

  test('manager takeover PIN opens sellable shift without second open count', async ({ page }, testInfo) => {
    prepareCleanShift();
    await startFreshHandoverShift(page, 'cashier', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    // Use the live expected float for takeover close-count (same as open float after clean open).
    let amount = process.env.POSMAIN_TEST_OPENING_CASH || '100.00';
    const previewResp = await page.request.get('/do/do_begin_shift_close_count.php').catch(() => null);
    if (previewResp) {
      const preview = await previewResp.json().catch(() => null) as {
        data?: { expected_cash?: unknown };
      } | null;
      const expected = preview?.data?.expected_cash;
      if (expected != null && expected !== '') {
        const n = Number(String(expected).replace(/,/g, ''));
        if (Number.isFinite(n)) {
          amount = n.toFixed(2);
        }
      }
    }

    await logoutIfNeeded(page);
    await loginAndUnlockPos(page, 'manager');

    const recovery = page.locator('#posShiftRecoveryOverlay');
    await expect(recovery).toBeVisible({ timeout: 20_000 });
    await expect(page.getByTestId('pos-choice-takeover')).toBeVisible();

    await page.getByTestId('pos-choice-takeover').click();
    await page.getByTestId('pos-takeover-amount').fill(amount);

    const takeoverBtn = page.getByTestId('pos-takeover-shift');
    await takeoverBtn.click();
    if (await page.getByTestId('pos-takeover-amount').isVisible().catch(() => false)) {
      await page.getByTestId('pos-takeover-amount').fill(amount);
      await takeoverBtn.click();
    }

    await expect(page.getByTestId('pos-takeover-reason')).toBeVisible({ timeout: 10_000 });
    await page.getByTestId('pos-takeover-reason').fill('إغلاق وردية الكاشير وفتح وردية المدير');

    const takeoverRespPromise = page.waitForResponse(
      (response) => response.url().includes('do_takeover_drawer_session.php')
        && response.request().method() === 'POST',
      { timeout: 30_000 },
    );
    await takeoverBtn.click();
    await enterManagerOverridePin(page, 'manager');

    // Prefer UI outcome: navigation can complete before response JSON is readable.
    await page.waitForURL(/pos_barcode\.php(?!\?shift=open_count)/, { timeout: 20_000 });
    const takeoverResp = await takeoverRespPromise.catch(() => null);
    if (takeoverResp) {
      const takeoverBody = await takeoverResp.json().catch(() => null) as {
        success?: boolean;
        data?: { next_state?: string };
      } | null;
      if (takeoverBody) {
        expect(takeoverBody.success).toBeTruthy();
        expect(takeoverBody.data?.next_state).toBe('selling_ready');
      }
    }

    await expect(page.locator('#posShiftRecoveryOverlay')).toBeHidden({ timeout: 15_000 });
    await expect(page.getByRole('dialog', { name: 'الصندوق مشغول' })).toHaveCount(0);
    // Close-count cash already opened the manager shift — no second open count.
    await expect(page.locator('#pshOpenOverlay')).toBeHidden({ timeout: 10_000 });
    await expect(page.locator('#posForm')).toBeVisible({ timeout: 15_000 });
    expect(page.url()).not.toMatch(/index\.php|logout=1/);
  });
});
