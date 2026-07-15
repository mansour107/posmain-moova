import { test, expect } from '@playwright/test';
import { loginAs, unlockPos, assertNoFatalText } from '../helpers/auth';
import {
  assertAdminReviewSurfacesReadable,
  assertCashModalTabBehavior,
  closeCloseShiftModal,
  closeShiftCashModal,
  closeShiftWithCountedCash,
  ensureOpeningBaselineIfOffered,
  forceCloseFirstOpenDrawer,
  formatCashAmount,
  goToCloseCountStep,
  openCloseShiftModal,
  openFirstCashFlowSessionDetail,
  prepareCleanShift,
  readHandoverPreview,
  recordCashSale,
  recordShiftPayIn,
  recordShiftPayOut,
  recordShiftSafeDrop,
  resolveFirstUnresolvedVariance,
  skipUnlessHandoverEnabled,
  startFreshHandoverShift,
} from '../helpers/handover';
import {
  assertModalUsable,
  assertNoJargonNoise,
  assertPageHealthy,
  assertReadableText,
  assertStatusPillsReadable,
  captureShiftShot,
} from '../helpers/shift_ux';
import { isAccessBlocked, isHandlerRejected } from '../helpers/rbac';
import type { PersonaRole } from '../helpers/env';

test.describe.serial('shift real-user campaign (all roles)', () => {
  let handoverEnabled = false;

  test.beforeAll(() => {
    prepareCleanShift();
  });

  test('prep: manager initializes opening baseline when offered', async ({ page }, testInfo) => {
    await loginAs(page, 'manager');
    handoverEnabled = await skipUnlessHandoverEnabled(page, testInfo);
    if (!handoverEnabled) {
      return;
    }
    await ensureOpeningBaselineIfOffered(page, '100.00');
    await captureShiftShot(page, '00-manager-closed-sessions-baseline');
  });

  test('C1: cashier open → sell → expense → blind close', async ({ page }, testInfo) => {
    test.skip(!handoverEnabled, 'Shift handover not enabled');
    await startFreshHandoverShift(page, 'cashier', '100.00');

    await expect(page.locator('#posForm')).toBeVisible({ timeout: 20_000 });
    await captureShiftShot(page, 'c1-cashier-pos-open');

    const saleNet = await recordCashSale(page);
    expect(saleNet).toBeGreaterThan(0);

    await recordShiftPayOut(page, '5.00', 'مصروف كاشير حملة e2e');
    await captureShiftShot(page, 'c1-cashier-after-expense');

    const preview = await readHandoverPreview(page);
    expect(preview.expectedCash).toBeNull();

    const counted = formatCashAmount(100 + saleNet - 5);
    await closeShiftWithCountedCash(page, counted);

    const resultModal = page.locator('#shiftCloseResultModal');
    if (await resultModal.isVisible({ timeout: 8_000 }).catch(() => false)) {
      await assertModalUsable(resultModal, {
        title: page.locator('#shiftCloseResultTitle, #shiftCloseResultModal .shift-close-title, #shiftCloseResultModal h2').first(),
        primaryAction: page.locator('#shiftCloseResultDismiss'),
      });
      await assertReadableText(page.locator('#shiftCloseResultTitle').first(), { label: 'close result title' });
      await assertReadableText(page.locator('.shift-close-hint').first(), {
        label: 'close result hint',
        minRatio: 3.0,
      });
      await captureShiftShot(page, 'c1-cashier-close-result');
      await Promise.all([
        page.waitForURL(/do_logout\.php|index\.php|login\.php/, { timeout: 15_000, waitUntil: 'commit' }),
        page.locator('#shiftCloseResultDismiss').click(),
      ]);
    } else {
      await expect(page.getByText(/تم إغلاق الشيفت|إغلاق الشيفت/i).first()).toBeVisible({ timeout: 10_000 });
    }
  });

  test('C2: cashier cannot see expected cash; pay-in/safe-drop need manager', async ({ page }, testInfo) => {
    test.skip(!handoverEnabled, 'Shift handover not enabled');
    await startFreshHandoverShift(page, 'cashier', '100.00');

    const preview = await readHandoverPreview(page);
    expect(preview.expectedCash).toBeNull();

    await assertCashModalTabBehavior(page, 'cashier');
    await captureShiftShot(page, 'c2-cashier-cash-modal-safe-drop');
    await closeShiftCashModal(page);

    await openCloseShiftModal(page);
    await goToCloseCountStep(page);
    await expect(page.locator('#pshCloseStep-count')).not.toContainText(/النقدية المتوقعة/i);
    await captureShiftShot(page, 'c2-cashier-blind-close-step');
    await closeCloseShiftModal(page);
  });

  test('W1: waiter has no shift money mission; cash-flow denied', async ({ page }) => {
    test.skip(!handoverEnabled, 'Shift handover not enabled');
    await loginAs(page, 'waiter');

    await page.goto('/dashboard.php');
    await expect(page.locator('a[href="cash_flow_report.php"]')).toHaveCount(0);
    await expect(page.locator('a[href="closed_sessions.php"]')).toHaveCount(0);
    await captureShiftShot(page, 'w1-waiter-sidebar-no-shift-links');

    const cashFlow = await page.goto('/cash_flow_report.php');
    const cashBody = await page.content();
    expect(isAccessBlocked(cashFlow, cashBody, page.url(), '/cash_flow_report.php')).toBeTruthy();

    const closed = await page.goto('/closed_sessions.php');
    const closedBody = await page.content();
    expect(isAccessBlocked(closed, closedBody, page.url(), '/closed_sessions.php')).toBeTruthy();

    await unlockPos(page, 'waiter');
    const openOverlay = page.locator('#pshOpenOverlay');
    const deniedPanel = page.getByTestId('psh-open-permission-denied');
    await expect(openOverlay).toBeVisible({ timeout: 15_000 });
    await expect(deniedPanel).toBeVisible();
    await expect(deniedPanel).toContainText(/ليس لديك صلاحية افتتاح الدرج/i);
    await expect(page.locator('#pshOpenCountStep')).toBeHidden();
    await expect(page.locator('[data-psh-open-submit]')).toBeHidden();
    await expect(page.locator('a[data-psh-open-lock]')).toBeVisible();
    await captureShiftShot(page, 'w1-waiter-pos');
  });

  test('K1: kitchen denied shift review and money pages', async ({ page }) => {
    test.skip(!handoverEnabled, 'Shift handover not enabled');
    await loginAs(page, 'kitchen');

    await page.goto('/dashboard.php');
    await expect(page.locator('a[href="cash_flow_report.php"]')).toHaveCount(0);
    await expect(page.locator('a[href="closed_sessions.php"]')).toHaveCount(0);

    for (const path of ['/cash_flow_report.php', '/closed_sessions.php', '/drawer_session.php?id=1'] as const) {
      const response = await page.goto(path);
      const body = await page.content();
      expect(isAccessBlocked(response, body, page.url(), path.split('?')[0]), `kitchen must be denied ${path}`).toBeTruthy();
    }
    await captureShiftShot(page, 'k1-kitchen-denied');
  });

  test('M1: manager pay-in + safe-drop + expense → matched close', async ({ page }) => {
    test.setTimeout(180_000);
    test.skip(!handoverEnabled, 'Shift handover not enabled');
    await startFreshHandoverShift(page, 'manager', '100.00');

    await assertCashModalTabBehavior(page, 'manager');
    await closeShiftCashModal(page);

    const saleNet = await recordCashSale(page);
    await recordShiftPayIn(page, '20.00', 'فكة حملة e2e');
    await recordShiftSafeDrop(page, '10.00', 'تحويل خزنة حملة e2e');
    await recordShiftPayOut(page, '5.00', 'مصروف مدير حملة e2e');

    await captureShiftShot(page, 'm1-manager-after-money-moves');

    const preview = await readHandoverPreview(page);
    expect(preview.expectedCash).not.toBeNull();
    expect(saleNet).toBeGreaterThan(0);

    await closeShiftWithCountedCash(page, formatCashAmount(preview.expectedCash!));
    await expect(page.getByText(/تم إغلاق الشيفت/i).first()).toBeVisible({ timeout: 12_000 });
    await captureShiftShot(page, 'm1-manager-close-result');
  });

  test('M2: manager variance close → unresolved → resolve', async ({ page }) => {
    test.setTimeout(180_000);
    test.skip(!handoverEnabled, 'Shift handover not enabled');
    await startFreshHandoverShift(page, 'manager', '100.00');

    await recordCashSale(page);
    const preview = await readHandoverPreview(page);
    expect(preview.expectedCash).not.toBeNull();
    const wrong = formatCashAmount((preview.expectedCash || 0) + 25);

    await closeShiftWithCountedCash(page, wrong, { waitForVariance: true });
    await captureShiftShot(page, 'm2-manager-variance-close');

    await loginAs(page, 'manager');
    await page.goto('/closed_sessions.php');
    await assertPageHealthy(page, /الشيفتات المغلقة/i);
    await expect(page.locator('text=حالات تحتاج مراجعة')).toBeVisible({ timeout: 15_000 });
    await expect(page.locator('[data-bs-target="#resolveDrawerModal"]').first()).toBeVisible();
    await captureShiftShot(page, 'm2-unresolved-queue');

    const resolveBtn = page.locator('[data-bs-target="#resolveDrawerModal"]').first();
    await resolveBtn.click();
    const resolveModal = page.locator('#resolveDrawerModal');
    await assertModalUsable(resolveModal, {
      primaryAction: resolveModal.locator('button[type="submit"]'),
    });
    await captureShiftShot(page, 'm2-resolve-modal');
    await page.keyboard.press('Escape');

    const sessionId = await resolveFirstUnresolvedVariance(page, 'تسوية حملة e2e');
    expect(sessionId).toBeGreaterThan(0);
  });

  test('M3: force-close path reachable and clear', async ({ page }) => {
    test.skip(!handoverEnabled, 'Shift handover not enabled');
    // Keep the same manager session that owns the open drawer (no re-login).
    await startFreshHandoverShift(page, 'manager', '100.00');

    await page.goto('/closed_sessions.php');
    await assertPageHealthy(page, /الشيفتات المغلقة/i);
    await expect(page.locator('[data-bs-target="#forceCloseDrawerModal"]').first()).toBeVisible({
      timeout: 15_000,
    });
    await captureShiftShot(page, 'm3-closed-sessions-open-drawers');

    const forced = await forceCloseFirstOpenDrawer(page, '100.00', 'إغلاق قسري حملة e2e');
    expect(forced).toBeTruthy();
    await captureShiftShot(page, 'm3-after-force-close');
  });

  test('O1: owner cash-flow review is clear and drillable', async ({ page }) => {
    test.skip(!handoverEnabled, 'Shift handover not enabled');
    await loginAs(page, 'admin');

    const start = new Date();
    start.setDate(start.getDate() - 6);
    const dateFrom = start.toISOString().slice(0, 10);
    const dateTo = new Date().toISOString().slice(0, 10);
    await page.goto(`/cash_flow_report.php?date_from=${dateFrom}&date_to=${dateTo}`);

    await assertPageHealthy(page, /التدفق النقدي/i);
    await expect(page.locator('.pr-verdict')).toBeVisible();
    await expect(page.getByTestId('cash-flow-tab-sessions')).toHaveClass(/is-active/);

    const sessionRows = page.getByTestId('cash-flow-panel-sessions').locator('tbody tr');
    const rowCount = await sessionRows.count();
    expect(rowCount).toBeLessThanOrEqual(10);

    await assertNoJargonNoise(page, [/تسوية المبيعات/i, /معد فعلياً/i]);
    await assertStatusPillsReadable(page);
    await captureShiftShot(page, 'o1-cash-flow-overview');

    const varianceChip = page.locator('[data-testid="cash-flow-filter-variance"], button, a').filter({ hasText: /بها فرق|فرق/i }).first();
    if (await varianceChip.isVisible().catch(() => false)) {
      await varianceChip.click();
      await captureShiftShot(page, 'o1-cash-flow-variance-filter');
    }

    const drilled = await openFirstCashFlowSessionDetail(page);
    if (drilled) {
      await assertPageHealthy(page);
      await expect(
        page.getByRole('heading', { name: /محاولات العد|سجل الحركات|سجل حركات الدرج|سجل الحلول/i }).first(),
      ).toBeVisible({ timeout: 15_000 });
      await captureShiftShot(page, 'o1-drawer-session-detail');
    }
  });

  test('O2: owner closed_sessions primary actions are obvious', async ({ page }) => {
    test.skip(!handoverEnabled, 'Shift handover not enabled');
    await loginAs(page, 'admin');

    await page.goto('/closed_sessions.php');
    await assertPageHealthy(page, /الشيفتات المغلقة/i);
    await assertReadableText(page.locator('h1').first(), { label: 'closed sessions title' });
    await captureShiftShot(page, 'o2-closed-sessions');

    const body = await page.content();
    assertNoFatalText(body);
  });

  test('S1: cross-role shift money denials', async ({ page }) => {
    test.skip(!handoverEnabled, 'Shift handover not enabled');

    const denialMatrix: Array<{ role: PersonaRole; path: string }> = [
      { role: 'cashier', path: '/cash_flow_report.php' },
      { role: 'cashier', path: '/drawer_session.php?id=1' },
      { role: 'waiter', path: '/cash_flow_report.php' },
      { role: 'waiter', path: '/closed_sessions.php' },
      { role: 'kitchen', path: '/cash_flow_report.php' },
      { role: 'kitchen', path: '/closed_sessions.php' },
    ];

    for (const row of denialMatrix) {
      await loginAs(page, row.role);
      const response = await page.goto(row.path);
      const body = await page.content();
      expect(
        isAccessBlocked(response, body, page.url(), row.path.split('?')[0]),
        `${row.role} must be denied ${row.path}`,
      ).toBeTruthy();
    }

    const handlerDenials: Array<{ role: PersonaRole; path: string }> = [
      { role: 'cashier', path: '/do/do_record_shift_payin.php' },
      { role: 'cashier', path: '/do/do_record_shift_safe_drop.php' },
      { role: 'cashier', path: '/do/do_force_close_drawer.php' },
      { role: 'cashier', path: '/do/do_resolve_drawer_session.php' },
      { role: 'cashier', path: '/do/do_set_opening_float_baseline.php' },
      { role: 'waiter', path: '/do/do_record_shift_payin.php' },
      { role: 'waiter', path: '/do/do_record_shift_safe_drop.php' },
      { role: 'kitchen', path: '/do/do_record_shift_expense.php' },
    ];

    for (const row of handlerDenials) {
      await loginAs(page, row.role);
      const response = await page.request.post(row.path, {
        form: {
          amount: '1',
          reason: 'rbac-denied',
          counted_cash: '1',
          notes: 'rbac',
          opening_float_baseline: '100',
          drawer_session_id: '1',
        },
        failOnStatusCode: false,
        maxRedirects: 0,
      });
      const rejected = isHandlerRejected(response) || response.status() >= 400;
      const json = await response.json().catch(() => null) as { success?: boolean } | null;
      const softDeny = json && json.success === false;
      expect(rejected || softDeny, `${row.role} POST ${row.path} must be denied`).toBeTruthy();
    }
  });
});
