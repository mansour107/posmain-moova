import { test, expect } from '@playwright/test';
import {
  closeShiftWithCountedCash,
  closeViaZReport,
  formatCashAmount,
  openCloseShiftModal,
  goToCloseCountStep,
  prepareCleanShift,
  readHandoverPreview,
  recordCashSale,
  recordShiftPayIn,
  resolveFirstUnresolvedVariance,
  expectSessionNotUnresolved,
  skipUnlessHandoverEnabled,
  startFreshHandoverShift,
  submitOpenCountUi,
  submitOpenCountViaApi,
  beginOpenCountViaApi,
  beginCloseCountViaApi,
  submitCloseCountViaApi,
  ensureOpeningBaselineIfOffered,
  forceCloseFirstOpenDrawer,
  assertBranchBlockedOverlay,
  takeoverBlockedDrawerFromOverlay,
} from '../helpers/handover';
import { loginAs, unlockPos } from '../helpers/auth';
import { fillOpenShiftCount } from '../helpers/shift';

test.describe.serial('manager: shift handover production scenarios', () => {
  test.beforeAll(() => {
    prepareCleanShift();
  });

  test('cold start: manager can initialize opening baseline when offered', async ({ page }, testInfo) => {
    await loginAs(page, 'manager');
    const initialized = await ensureOpeningBaselineIfOffered(page, '100.00');
    if (!initialized) {
      testInfo.annotations.push({
        type: 'note',
        description: 'Baseline panel not shown (already initialized or branch has sessions)',
      });
    }
  });

  test('opening count is blind and completes shift open', async ({ page }, testInfo) => {
    await startFreshHandoverShift(page, 'manager', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    await expect(page.locator('#posForm')).toBeVisible({ timeout: 20_000 });
    const preview = await readHandoverPreview(page);
    expect(preview.totalOrders).toBeGreaterThanOrEqual(0);
  });

  test('full shift: cash sale + pay-in + matched close count', async ({ page }, testInfo) => {
    await startFreshHandoverShift(page, 'manager', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    const saleNet = await recordCashSale(page);
    await recordShiftPayIn(page, '15.00', 'فكة للدرج e2e');

    const preview = await readHandoverPreview(page);
    expect(preview.expectedCash).not.toBeNull();

    await closeShiftWithCountedCash(page, formatCashAmount(preview.expectedCash!));
    await expect(page.getByText(/تم إغلاق الشيفت/i)).toBeVisible({ timeout: 10_000 });
    expect(saleNet).toBeGreaterThan(0);
  });

  test('opening recount: mismatch then correction still opens shift', async ({ page }, testInfo) => {
    prepareCleanShift();
    await loginAs(page, 'manager');
    await ensureOpeningBaselineIfOffered(page, '100.00');
    await unlockPos(page, 'manager');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    const overlay = page.locator('#pshOpenOverlay');
    await expect(overlay).toBeVisible({ timeout: 20_000 });

    await page.locator('#pshOpenAmount').fill('999.00');
    await page.locator('[data-psh-open-submit]').click();
    await expect(page.locator('#pshOpenMessage')).toContainText(/إعادة العد|بعناية/i, { timeout: 10_000 });

    // Correction attempt — helper handles matched open or variance acknowledge.
    await submitOpenCountUi(page, '100.00');
    await expect(overlay).toBeHidden({ timeout: 5_000 });
  });

  test('closing variance: wrong count surfaces in admin queue and can be resolved', async ({ page }, testInfo) => {
    await startFreshHandoverShift(page, 'manager', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    const previewBeforeClose = await readHandoverPreview(page);
    const wrongAmount = previewBeforeClose.expectedCash != null
      ? formatCashAmount(previewBeforeClose.expectedCash - 5)
      : '95.00';

    await closeShiftWithCountedCash(page, wrongAmount, {
      waitForVariance: true,
      notes: 'e2e variance close',
    });

    await page.goto('/closed_sessions.php');
    await expect(page.locator('text=حالات تحتاج مراجعة')).toBeVisible({ timeout: 15_000 });

    // Resolve the newest unresolved closing variance (first resolve button).
    const sessionId = await resolveFirstUnresolvedVariance(page, 'تمت المراجعة — e2e production test');
    expect(sessionId).toBeGreaterThan(0);
    await expectSessionNotUnresolved(page, sessionId);

    await page.goto(`/drawer_session.php?id=${sessionId}`);
    await expect(page.getByRole('heading', { name: /محاولات العد|سجل الحركات|سجل الحلول/i }).first()).toBeVisible({
      timeout: 10_000,
    });
  });

  test('close count step hides expected cash from blind UI', async ({ page }, testInfo) => {
    await startFreshHandoverShift(page, 'manager', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    await openCloseShiftModal(page);
    await goToCloseCountStep(page);
    await expect(page.locator('#pshCloseAmount')).toBeVisible();
    await expect(page.locator('#pshCloseStep-count')).not.toContainText(/النقدية المتوقعة/i);
  });

  test('idempotency: duplicate open-count submit replays without error', async ({ page }, testInfo) => {
    prepareCleanShift();
    await loginAs(page, 'manager');
    await ensureOpeningBaselineIfOffered(page, '75.00');
    await unlockPos(page, 'manager');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    const begin = await beginOpenCountViaApi(page) as { success?: boolean; error?: string };
    if (!begin.success && begin.error === 'OPENING_BASELINE_REQUIRED') {
      throw new Error('Baseline still required after ensureOpeningBaselineIfOffered');
    }

    const overlay = page.locator('#pshOpenOverlay');
    await expect(overlay).toBeVisible({ timeout: 20_000 });

    const key = `e2e-open-idem-${Date.now()}`;
    const first = await submitOpenCountViaApi(page.request, page, '75.00', key) as {
      success?: boolean;
      idempotency_replayed?: boolean;
    };
    const second = await submitOpenCountViaApi(page.request, page, '75.00', key) as {
      success?: boolean;
      idempotency_replayed?: boolean;
    };

    expect(first).toMatchObject({ success: true });
    expect(second).toMatchObject({ success: true });
    expect(second.idempotency_replayed).toBe(true);
  });

  test('idempotency: duplicate close-count submit replays without error', async ({ page }, testInfo) => {
    await startFreshHandoverShift(page, 'manager', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    await openCloseShiftModal(page);
    await goToCloseCountStep(page);

    const beginClose = await beginCloseCountViaApi(page) as { success?: boolean };
    expect(beginClose.success).toBe(true);

    const preview = await readHandoverPreview(page);
    const amount = preview.expectedCash != null ? formatCashAmount(preview.expectedCash) : '100.00';
    const key = `e2e-close-idem-${Date.now()}`;

    const first = await submitCloseCountViaApi(page.request, page, amount, key) as {
      success?: boolean;
      idempotency_replayed?: boolean;
    };
    const second = await submitCloseCountViaApi(page.request, page, amount, key) as {
      success?: boolean;
      idempotency_replayed?: boolean;
    };

    expect(first).toMatchObject({ success: true });
    expect(second).toMatchObject({ success: true });
    expect(second.idempotency_replayed).toBe(true);
  });

  test('UI double-submit open count does not create duplicate sessions', async ({ page }, testInfo) => {
    prepareCleanShift();
    await loginAs(page, 'manager');
    await ensureOpeningBaselineIfOffered(page, '100.00');
    await unlockPos(page, 'manager');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    const overlay = page.locator('#pshOpenOverlay');
    await expect(overlay).toBeVisible({ timeout: 20_000 });

    const sessionIds = new Set<number>();
    page.on('response', async (response) => {
      if (!response.url().includes('do_submit_shift_open_count.php') || response.request().method() !== 'POST') {
        return;
      }
      try {
        const body = await response.json() as {
          success?: boolean;
          data?: { drawer_session_id?: number };
        };
        const id = Number(body?.data?.drawer_session_id || 0);
        if (body?.success && id > 0) {
          sessionIds.add(id);
        }
      } catch {
        // ignore non-JSON
      }
    });

    await page.locator('#pshOpenAmount').fill('100.00');
    const submit = page.locator('[data-psh-open-submit]');
    await submit.click();
    await submit.click({ force: true }).catch(() => undefined);

    // Race may leave recount/empty-amount/variance UI — finish with the shared helper.
    if (await overlay.isVisible().catch(() => false)) {
      await submitOpenCountUi(page, '100.00');
    }

    await expect(overlay).toBeHidden({ timeout: 20_000 });
    await expect(page.locator('#posForm')).toBeVisible();
    expect(sessionIds.size).toBeLessThanOrEqual(1);

    const status = await page.evaluate(async () => {
      const response = await fetch('pos_session_status.php', { cache: 'no-store' });
      return response.json();
    });
    expect(Number(status?.drawer_session_id || 0)).toBeGreaterThan(0);
  });

  test('manager can force-close an open drawer from closed sessions', async ({ page }, testInfo) => {
    await startFreshHandoverShift(page, 'manager', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    const forced = await forceCloseFirstOpenDrawer(page, '100.00', 'e2e force close production test');
    expect(forced).toBe(true);
    await expect(page.getByText(/تم إغلاق جلسة الدرج|إغلاق/i).first()).toBeVisible({ timeout: 10_000 });
  });

  test('cashier blocked overlay names holder; manager PIN takeover then open count', async ({
    page,
    browser,
  }, testInfo) => {
    prepareCleanShift();
    await startFreshHandoverShift(page, 'manager', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    const beginWhileManagerOpen = await beginOpenCountViaApi(page);
    // Manager already has the open drawer — begin for same user should not branch-block.
    expect((beginWhileManagerOpen as { error?: string }).error !== 'BRANCH_DRAWER_ALREADY_OPEN').toBeTruthy();

    const cashierContext = await browser.newContext();
    const cashierPage = await cashierContext.newPage();
    try {
      await loginAs(cashierPage, 'cashier');
      await unlockPos(cashierPage, 'cashier');
      if (!(await skipUnlessHandoverEnabled(cashierPage, testInfo))) {
        return;
      }

      const begin = await beginOpenCountViaApi(cashierPage) as {
        success?: boolean;
        error?: string;
        blocking_session?: { cashier_name?: string; drawer_session_id?: number };
      };
      expect(begin.error).toBe('BRANCH_DRAWER_ALREADY_OPEN');
      expect(begin.blocking_session?.cashier_name).toBeTruthy();
      expect(Number(begin.blocking_session?.drawer_session_id || 0)).toBeGreaterThan(0);

      await assertBranchBlockedOverlay(cashierPage, begin.blocking_session?.cashier_name);
      await takeoverBlockedDrawerFromOverlay(
        cashierPage,
        '100.00',
        'e2e manager takeover from blocked overlay',
      );
      await submitOpenCountUi(cashierPage, '100.00');
      await expect(cashierPage.locator('#posForm')).toBeVisible({ timeout: 20_000 });
    } finally {
      await cashierContext.close();
    }
  });

  test('Z-report happy path closes shift with handover close token', async ({ page }, testInfo) => {
    await startFreshHandoverShift(page, 'manager', '100.00');
    if (!(await skipUnlessHandoverEnabled(page, testInfo))) {
      return;
    }

    const preview = await readHandoverPreview(page);
    const amount = preview.expectedCash != null ? formatCashAmount(preview.expectedCash) : '100.00';
    await closeViaZReport(page, amount, 'e2e z-report close');
  });
});
