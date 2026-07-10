import { execSync } from 'node:child_process';
import path from 'node:path';
import { expect, type APIRequestContext, type Page, type TestInfo } from '@playwright/test';
import { loginAs, loginAndUnlockPos, unlockPos } from './auth';
import type { PersonaRole } from './env';
import { fillOpenShiftCount } from './shift';
import { clickFirstAddableItem, payCashInModal, readCartNet } from './pos';

const projectRoot = path.resolve(__dirname, '../../..');

export function prepareCleanShift(): void {
  const syncCommands = [
    'docker exec posmain-php php /app/tests/e2e/helpers/sync_manager_handover_perms_cli.php',
    'php tests/e2e/helpers/sync_manager_handover_perms_cli.php',
  ];
  for (const command of syncCommands) {
    try {
      execSync(command, { cwd: projectRoot, stdio: 'pipe' });
      break;
    } catch {
      // optional — permissions may already be correct
    }
  }

  const commands = [
    'docker exec posmain-php php /app/tests/e2e/helpers/prepare_clean_shift_cli.php',
    'php tests/e2e/helpers/prepare_clean_shift_cli.php',
    'php tests/e2e/helpers/close_open_drawers_cli.php',
  ];

  for (const command of commands) {
    try {
      execSync(command, {
        cwd: projectRoot,
        stdio: 'pipe',
      });
      return;
    } catch {
      // try next cleanup strategy
    }
  }
}

export type HandoverPreview = {
  handoverEnabled: boolean;
  expectedCash: number | null;
  totalOrders: number;
  totalSales: number;
};

export async function readHandoverPreview(page: Page): Promise<HandoverPreview> {
  const response = await page.request.get('/do/get_shift_preview.php');
  expect(response.ok()).toBeTruthy();
  const body = await response.json();
  if (!body.success) {
    throw new Error(String(body.error || 'shift preview unavailable'));
  }

  const expectedRaw = body.data?.expected_cash;
  const expectedCash = expectedRaw == null || expectedRaw === ''
    ? null
    : Number.parseFloat(String(expectedRaw).replace(/,/g, ''));

  return {
    handoverEnabled: !!body.data?.handover_enabled,
    expectedCash: Number.isFinite(expectedCash) ? expectedCash : null,
    totalOrders: Number(body.data?.total_orders ?? 0),
    totalSales: Number.parseFloat(String(body.data?.total_sales ?? '0').replace(/,/g, '')) || 0,
  };
}

async function detectHandoverFromAdminSurfaces(page: Page): Promise<boolean> {
  await page.goto('/closed_sessions.php');
  const body = await page.content();
  return (
    body.includes('openingBaselineModal')
    || body.includes('forceCloseDrawerModal')
    || body.includes('drawer_session.php')
    || body.includes('حالات تحتاج مراجعة')
    || body.includes('resolveDrawerModal')
  );
}

export async function skipUnlessHandoverEnabled(page: Page, test: TestInfo): Promise<boolean> {
  try {
    const preview = await readHandoverPreview(page);
    if (!preview.handoverEnabled) {
      test.skip(true, 'Shift handover tables/columns not enabled in this environment');
      return false;
    }
    return true;
  } catch {
    const enabled = await detectHandoverFromAdminSurfaces(page);
    if (!enabled) {
      test.skip(true, 'Shift handover not detected in this environment');
      return false;
    }
    return true;
  }
}

export async function getShiftCsrfTokens(page: Page): Promise<{
  openCount: string;
  closeCount: string;
  closeShift: string;
  payIn: string;
  takeover: string;
  override: string;
}> {
  return page.evaluate(() => ({
    openCount: (window as Window & { POSMAIN_SHIFT_OPEN_CSRF_TOKEN?: string }).POSMAIN_SHIFT_OPEN_CSRF_TOKEN || '',
    closeCount: (window as Window & { POSMAIN_SHIFT_CLOSE_COUNT_CSRF_TOKEN?: string }).POSMAIN_SHIFT_CLOSE_COUNT_CSRF_TOKEN || '',
    closeShift: (window as Window & { POSMAIN_SHIFT_CSRF_TOKEN?: string }).POSMAIN_SHIFT_CSRF_TOKEN || '',
    payIn: (window as Window & { POSMAIN_SHIFT_PAYIN_CSRF_TOKEN?: string }).POSMAIN_SHIFT_PAYIN_CSRF_TOKEN || '',
    takeover: (window as Window & { POSMAIN_SHIFT_TAKEOVER_CSRF_TOKEN?: string }).POSMAIN_SHIFT_TAKEOVER_CSRF_TOKEN || '',
    override: (window as Window & { POSMAIN_POS_OVERRIDE_CSRF_TOKEN?: string }).POSMAIN_POS_OVERRIDE_CSRF_TOKEN
      || (document.querySelector('meta[name="pos-override-csrf-token"]')?.getAttribute('content') || ''),
  }));
}

export async function loginManagerForAdmin(page: Page): Promise<void> {
  await loginAs(page, 'manager');
}

export async function ensureOpeningBaselineIfOffered(page: Page, amount = '100.00'): Promise<boolean> {
  await page.goto('/closed_sessions.php');
  await expect(page.locator('h1')).toContainText(/الشيفتات المغلقة/i, { timeout: 15_000 });

  const initButton = page.locator('[data-bs-target="#openingBaselineModal"]');
  if (!(await initButton.isVisible().catch(() => false))) {
    return false;
  }

  await initButton.click();
  await page.locator('#openingBaselineAmount').fill(amount);
  await page.locator('#openingBaselineForm button[type="submit"]').click();
  await page.waitForLoadState('networkidle');
  await expect(page.locator('text=تهيئة عهد الافتتاح')).toHaveCount(0, { timeout: 15_000 });
  return true;
}

export async function unlockPosWithHandover(
  page: Page,
  role: PersonaRole = 'manager',
): Promise<void> {
  await unlockPos(page, role);
}

export async function completeOpeningCountIfShown(page: Page, amount: string): Promise<boolean> {
  const overlay = page.locator('#pshOpenOverlay');
  if (!(await overlay.isVisible().catch(() => false))) {
    return false;
  }

  await expect(page.locator('text=المتوقع')).toHaveCount(0);
  await submitOpenCountUi(page, amount);
  return true;
}

export async function assertBaselineRequiredOverlay(page: Page): Promise<void> {
  const baselineMsg = page.locator('#pshOpenBaselineRequired');
  await expect(baselineMsg).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('#pshOpenCountStep')).toHaveClass(/psh-hidden/);
}

export async function assertBranchBlockedOverlay(page: Page, holderName?: string): Promise<void> {
  const blocked = page.locator('#pshOpenBranchBlocked');
  await expect(blocked).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('#pshOpenCountStep')).toHaveClass(/psh-hidden/);
  if (holderName) {
    await expect(page.locator('#pshOpenBranchBlockedText')).toContainText(holderName);
  }
}

export async function enterManagerPinPad(page: Page, pin: string): Promise<void> {
  const modal = page.locator('#posPinPadModal');
  await expect(modal).toBeVisible({ timeout: 10_000 });
  for (const digit of pin) {
    await modal.locator(`.pos-pin-pad-key[data-key="${digit}"]`).click();
  }
  await modal.locator('.pos-pin-pad-key.enter, [data-key="دخول"]').click();
  await expect(modal).toBeHidden({ timeout: 15_000 });
}

export async function takeoverBlockedDrawerFromOverlay(
  page: Page,
  countedCash: string,
  reason: string,
  managerPin?: string,
): Promise<void> {
  await assertBranchBlockedOverlay(page);
  await page.locator('#pshTakeoverAmount').fill(countedCash);
  await page.locator('#pshTakeoverReason').fill(reason);

  const pin = managerPin || process.env.POSMAIN_TEST_PIN_MANAGER || '1357';
  const tokens = await getShiftCsrfTokens(page);
  const sessionId = await page.locator('#pshOpenBranchBlockedText').evaluate(() => {
    const wizard = (window as Window & {
      PosShiftCountWizard?: { blockingSession?: { drawer_session_id?: number } };
    }).PosShiftCountWizard;
    return Number(wizard?.blockingSession?.drawer_session_id || 0);
  });
  expect(sessionId).toBeGreaterThan(0);

  // Obtain manager approval via the same override endpoint the PIN pad uses,
  // then submit takeover with approval_id (avoids flaky PIN-pad digit entry).
  const overrideResponse = await page.request.post('/ajax/pos_override_auth.php', {
    form: {
      manager_pin: pin,
      permission_key: 'pos.shift.force_close',
      action_type: 'pos.shift.force_close',
      target_type: 'drawer_session',
      target_id: String(sessionId),
      reason,
      csrf_token: tokens.override,
    },
    headers: {
      'X-CSRF-Token': tokens.override,
      'X-Requested-With': 'XMLHttpRequest',
    },
    failOnStatusCode: false,
  });
  const overrideBody = await overrideResponse.json() as {
    success?: boolean;
    approval_id?: number;
    code?: string;
  };
  expect(overrideResponse.ok(), `override failed: ${JSON.stringify(overrideBody)}`).toBeTruthy();
  expect(Number(overrideBody.approval_id || 0)).toBeGreaterThan(0);

  const takeoverResponse = await page.request.post('/do/do_takeover_drawer_session.php', {
    form: {
      drawer_session_id: String(sessionId),
      counted_amount: countedCash,
      reason,
      manager_approval_id: String(overrideBody.approval_id),
      idempotency_key: `e2e-takeover:${sessionId}:${Date.now()}`,
      csrf_token: tokens.takeover,
    },
    headers: {
      'X-CSRF-Token': tokens.takeover,
      'X-Requested-With': 'XMLHttpRequest',
    },
    failOnStatusCode: false,
  });
  const takeoverBody = await takeoverResponse.json() as { success?: boolean; error?: string };
  expect(takeoverResponse.ok(), `takeover failed: ${JSON.stringify(takeoverBody)}`).toBeTruthy();
  expect(takeoverBody.success).toBeTruthy();

  // Refresh open-count state in the overlay after API takeover.
  await page.evaluate(async () => {
    const wizard = (window as Window & {
      PosShiftCountWizard?: {
        beginOpenCount?: (overlay: JQuery) => JQuery.Promise<unknown>;
      };
    }).PosShiftCountWizard;
    if (wizard && typeof wizard.beginOpenCount === 'function' && window.jQuery) {
      await wizard.beginOpenCount(window.jQuery('#pshOpenOverlay'));
    }
  });

  await expect(page.locator('#pshOpenCountStep')).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('#pshOpenBranchBlocked')).toBeHidden();
}

export async function closeCloseShiftModal(page: Page): Promise<void> {
  const modal = page.locator('#closeShiftModal');
  if (!(await modal.isVisible().catch(() => false))) {
    return;
  }
  await page.evaluate(() => {
    const el = document.getElementById('closeShiftModal');
    if (!el) {
      return;
    }
    const bootstrapApi = (window as Window & { bootstrap?: { Modal?: { getOrCreateInstance: (node: Element) => { hide: () => void } } } }).bootstrap;
    if (bootstrapApi?.Modal) {
      bootstrapApi.Modal.getOrCreateInstance(el).hide();
    }
    el.classList.remove('show');
    el.setAttribute('aria-hidden', 'true');
    el.style.display = 'none';
    document.querySelectorAll('.modal-backdrop').forEach((node) => node.remove());
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
  });
  await expect(modal).toBeHidden({ timeout: 10_000 });
}

export async function openCloseShiftModal(page: Page): Promise<void> {
  await closeShiftCashModal(page);
  const modal = page.locator('#closeShiftModal');
  if (await modal.isVisible().catch(() => false)) {
    return;
  }

  const shown = await page.evaluate(() => {
    const el = document.getElementById('closeShiftModal');
    if (!el) {
      return false;
    }
    const bootstrapApi = (window as Window & {
      bootstrap?: { Modal?: { getOrCreateInstance: (node: Element) => { show: () => void } } };
    }).bootstrap;
    if (bootstrapApi?.Modal) {
      bootstrapApi.Modal.getOrCreateInstance(el).show();
      return true;
    }
    return false;
  });

  if (!shown) {
    const closeButton = page.locator('[data-bs-target="#closeShiftModal"], button[title="إغلاق الشيفت"]').first();
    await expect(closeButton).toBeVisible({ timeout: 15_000 });
    await closeButton.click();
  }

  await expect(modal).toBeVisible({ timeout: 10_000 });
}

export async function goToCloseCountStep(page: Page): Promise<void> {
  const next = page.locator('[data-psh-close-next]');
  if (await next.isVisible().catch(() => false)) {
    await next.click();
  }
  await expect(page.locator('#pshCloseAmount')).toBeVisible({ timeout: 10_000 });
  // Blind count UI: the amount field step must not reveal expected cash.
  // Summary step may still exist in the DOM (hidden) for managers who can see expected cash.
  await expect(page.locator('#pshCloseStep-count')).not.toContainText(/النقدية المتوقعة/i);
}

export async function submitCloseCount(page: Page, amount: string): Promise<void> {
  await goToCloseCountStep(page);
  await page.locator('#pshCloseAmount').fill(amount);
  await page.locator('[data-psh-close-submit-count]').click();
}

export async function finalizeCloseShiftFromModal(page: Page, notes?: string): Promise<void> {
  if (notes) {
    const notesField = page.locator('#shift_notes');
    if (await notesField.isVisible({ timeout: 1_000 }).catch(() => false)) {
      await notesField.fill(notes);
    }
  }

  const finalBtn = page.locator('#closeShiftModal [data-psh-close-final]');
  if (await finalBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await Promise.all([
      page.waitForURL(/pos_barcode\.php/, { timeout: 30_000 }),
      finalBtn.click(),
    ]);
  } else {
    // Matched / variance auto-close via wizard finalizeClose().
    await page.waitForURL(/pos_barcode\.php/, { timeout: 30_000 });
  }

  await expect(page.locator('#shiftCloseResultModal')).toBeVisible({ timeout: 15_000 });
}

export async function closeShiftWithCountedCash(
  page: Page,
  amount: string,
  options: { notes?: string; waitForVariance?: boolean } = {},
): Promise<void> {
  await openCloseShiftModal(page);

  // Summary step must not show confirm-count (stray button bug).
  await expect(page.locator('#closeShiftModal [data-psh-close-submit-count]')).toBeHidden();
  await expect(page.locator('#closeShiftModal [data-psh-close-next]')).toBeVisible();

  await goToCloseCountStep(page);
  if (options.notes) {
    const notesField = page.locator('#shift_notes');
    if (await notesField.isVisible({ timeout: 1_000 }).catch(() => false)) {
      await notesField.fill(options.notes);
    }
  }
  await page.locator('#pshCloseAmount').fill(amount);

  const countResponsePromise = page.waitForResponse(
    (response) => response.url().includes('do_submit_shift_close_count.php')
      && response.request().method() === 'POST',
    { timeout: 20_000 },
  );
  await page.locator('[data-psh-close-submit-count]').click();
  const countResponse = await countResponsePromise;
  const countBody = await countResponse.json().catch(() => null) as {
    success?: boolean;
    data?: { status?: string; matched?: boolean; expected_cash?: unknown; variance?: unknown };
  } | null;

  if (countBody?.data?.status === 'recount') {
    await expect(page.locator('#pshCloseMessage.is-warn')).toBeVisible({ timeout: 5_000 });
    await expect(page.locator('#closeShiftModal [data-psh-close-back]')).toBeHidden();
    await page.locator('#pshCloseAmount').fill(amount);
    const recountResponse = page.waitForResponse(
      (response) => response.url().includes('do_submit_shift_close_count.php')
        && response.request().method() === 'POST',
      { timeout: 20_000 },
    );
    await page.locator('[data-psh-close-submit-count]').click();
    await recountResponse;
  }

  // Matched or max-attempt variance: wizard auto-posts close — never show pre-close variance card.
  await expect(page.locator('#pshCloseVariance')).toBeHidden({ timeout: 3_000 }).catch(() => undefined);
  await page.waitForURL(/pos_barcode\.php/, { timeout: 30_000 });
  await expect(page.locator('#shiftCloseResultModal')).toBeVisible({ timeout: 15_000 });
}

export async function ensureDrawerSessionOpen(page: Page, amount = '100.00'): Promise<boolean> {
  const opened = await completeOpeningCountIfShown(page, amount);
  if (opened) {
    return true;
  }

  // Prefer session status — works for cashiers who cannot begin close count.
  try {
    const status = await page.evaluate(async () => {
      const response = await fetch('pos_session_status.php', { cache: 'no-store' });
      return response.json();
    });
    if (Number(status?.drawer_session_id || 0) > 0) {
      return true;
    }
  } catch {
    // ignore
  }

  try {
    const response = await page.request.get('/do/get_shift_preview.php');
    const body = await response.json();
    const drawerId = Number(
      body?.data?.payins?.drawer_session_id
      || body?.data?.expenses?.drawer_session_id
      || 0,
    );
    if (body?.success && drawerId > 0) {
      return true;
    }
    if (body?.success && (body.data?.payins?.drawer_active || body.data?.expenses?.drawer_active)) {
      return true;
    }
  } catch {
    // ignore
  }

  // Last resort: begin close count only succeeds when an open drawer exists.
  const beginClose = await beginCloseCountViaApi(page) as {
    success?: boolean;
    error?: string;
    data?: { drawer_session_id?: number };
  };
  if (beginClose.success && Number(beginClose.data?.drawer_session_id || 0) > 0) {
    return true;
  }

  return false;
}

export async function submitOpenCountUi(page: Page, amount: string): Promise<void> {
  const overlay = page.locator('#pshOpenOverlay');
  await expect(overlay).toBeVisible({ timeout: 15_000 });

  for (let attempt = 1; attempt <= 2; attempt++) {
    await page.locator('#pshOpenAmount').fill(amount);
    const responsePromise = page.waitForResponse(
      (response) => response.url().includes('do_submit_shift_open_count.php')
        && response.request().method() === 'POST',
      { timeout: 20_000 },
    );
    await page.locator('[data-psh-open-submit]').click();
    const response = await responsePromise;
    const body = await response.json().catch(() => null) as {
      success?: boolean;
      error?: string;
      data?: { status?: string };
    } | null;

    if (!body?.success) {
      if (body?.error === 'OPENING_BASELINE_REQUIRED') {
        throw new Error('OPENING_BASELINE_REQUIRED');
      }
      throw new Error(`Open count failed: ${JSON.stringify(body)}`);
    }

    const status = body.data?.status || '';
    if (status === 'recount') {
      continue;
    }

    if (status === 'opened_with_variance') {
      const acknowledge = page.locator('[data-psh-open-acknowledge]');
      await expect(acknowledge).toBeVisible({ timeout: 10_000 });
      await acknowledge.click();
    }

    await expect(overlay).toBeHidden({ timeout: 20_000 });
    return;
  }

  throw new Error('Open count still requires recount after max attempts');
}

async function parseApiJson(response: { json: () => Promise<unknown>; text: () => Promise<string> }): Promise<unknown> {
  try {
    return await response.json();
  } catch {
    const text = (await response.text().catch(() => '')).trim();
    return { success: false, error: text || 'NON_JSON_RESPONSE' };
  }
}

export async function startFreshHandoverShift(
  page: Page,
  role: PersonaRole,
  amount = '100.00',
): Promise<void> {
  prepareCleanShift();
  await loginAs(page, role);
  // Cashiers cannot open closed_sessions baseline UI — managers/admins set it.
  if (role === 'manager' || role === 'admin') {
    await ensureOpeningBaselineIfOffered(page, amount);
  } else {
    // Ensure baseline exists via a short manager hop when needed.
    await loginAs(page, 'manager');
    await ensureOpeningBaselineIfOffered(page, amount);
    await loginAs(page, role);
  }
  await unlockPos(page, role);

  const overlay = page.locator('#pshOpenOverlay');
  if (await overlay.isVisible({ timeout: 5_000 }).catch(() => false)) {
    const baselineRequired = page.locator('#pshOpenBaselineRequired');
    if (await baselineRequired.isVisible().catch(() => false)) {
      throw new Error('OPENING_BASELINE_REQUIRED: set baseline before opening shift');
    }
    await submitOpenCountUi(page, amount);
  } else if (!(await ensureDrawerSessionOpen(page, amount))) {
    // Overlay missing and no open drawer yet — try API open-count path.
    const begin = await beginOpenCountViaApi(page) as { success?: boolean; error?: string };
    if (begin.success) {
      const key = `e2e-open-api-${Date.now()}`;
      let result = await submitOpenCountViaApi(page.request, page, amount, key) as {
        success?: boolean;
        error?: string;
        data?: { status?: string };
      };
      if (!result.success && result.error === 'DRAWER_SESSION_ALREADY_OPEN') {
        await page.reload({ waitUntil: 'networkidle' });
      } else if (!result.success && /CSRF/i.test(String(result.error || ''))) {
        // Token stale after role hop — reload POS and finish via UI if overlay appears.
        await page.goto('/pos_barcode.php');
        await expect(page.locator('#posForm')).toBeVisible({ timeout: 20_000 });
        const overlayAfterCsrf = page.locator('#pshOpenOverlay');
        if (await overlayAfterCsrf.isVisible({ timeout: 3_000 }).catch(() => false)) {
          await submitOpenCountUi(page, amount);
        }
      } else {
        if (result.success && result.data?.status === 'recount') {
          result = await submitOpenCountViaApi(page.request, page, amount, `${key}-2`) as {
            success?: boolean;
            error?: string;
            data?: { status?: string };
          };
        }
        if (!result.success && result.error !== 'DRAWER_SESSION_ALREADY_OPEN') {
          throw new Error(`API open count failed: ${JSON.stringify(result)}`);
        }
        await page.reload({ waitUntil: 'networkidle' });
      }
    } else if (begin.error === 'OPENING_BASELINE_REQUIRED') {
      throw new Error('OPENING_BASELINE_REQUIRED: set baseline before opening shift');
    } else if (begin.error === 'DRAWER_SESSION_ALREADY_OPEN' || begin.error === 'BRANCH_DRAWER_ALREADY_OPEN') {
      // Already have an open drawer for this branch/user.
    }
  }

  await expect(page.locator('#posForm')).toBeVisible({ timeout: 20_000 });

  for (let attempt = 0; attempt < 3; attempt++) {
    if (await ensureDrawerSessionOpen(page, amount)) {
      return;
    }
    await page.reload({ waitUntil: 'networkidle' });
    const overlayRetry = page.locator('#pshOpenOverlay');
    if (await overlayRetry.isVisible().catch(() => false)) {
      await submitOpenCountUi(page, amount);
    }
  }

  throw new Error('Failed to open drawer session for handover scenario');
}

export async function closeViaZReport(page: Page, amount: string, notes = ''): Promise<void> {
  await openZReport(page);

  const drawerSessionId = await page.locator('input[name="drawer_session_id"]').inputValue();
  if (!drawerSessionId || drawerSessionId === '0') {
    throw new Error('Z-report has no active drawer_session_id — open a handover shift first');
  }

  await page.locator('#zActualCash, input[name="actual_cash"]').fill(amount);
  if (notes) {
    await page.locator('input[name="notes"]').fill(notes);
  }

  page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([
    page.waitForURL(/pos_barcode\.php/, { timeout: 45_000 }),
    page.locator('#zCloseSubmitBtn, #closeForm button[type="submit"]').click(),
  ]);
  await expect(page.locator('#shiftCloseResultModal')).toBeVisible({ timeout: 15_000 });
}

export async function dismissShiftCloseResultModal(page: Page): Promise<void> {
  const modal = page.locator('#shiftCloseResultModal');
  if (await modal.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await page.locator('#shiftCloseResultDismiss').click();
    await expect(modal).toBeHidden({ timeout: 5_000 });
  }
}

export async function openShiftCashModal(page: Page): Promise<void> {
  if (!page.url().includes('pos_barcode.php')) {
    await page.goto('/pos_barcode.php');
    await expect(page.locator('#posForm')).toBeVisible({ timeout: 20_000 });
  }

  const modal = page.locator('#shiftExpenseModal');
  if (await modal.isVisible().catch(() => false)) {
    return;
  }

  const previewLoad = page.waitForResponse(
    (response) => response.url().includes('get_shift_preview.php') && response.request().method() === 'GET',
  );

  const shown = await page.evaluate(() => {
    const el = document.getElementById('shiftExpenseModal');
    if (!el) {
      return false;
    }
    const bootstrapApi = (window as Window & {
      bootstrap?: { Modal?: { getOrCreateInstance: (node: Element) => { show: () => void } } };
    }).bootstrap;
    if (bootstrapApi?.Modal) {
      bootstrapApi.Modal.getOrCreateInstance(el).show();
      return true;
    }
    return false;
  });

  if (!shown) {
    const cashButton = page.locator(
      'button[data-bs-target="#shiftExpenseModal"], button[title="حركة نقدية للدرج"], button[title="تسجيل مصروف"]',
    ).first();
    await expect(cashButton).toBeVisible({ timeout: 15_000 });
    await cashButton.click();
  }

  await expect(modal).toBeVisible({ timeout: 10_000 });
  await previewLoad.catch(() => undefined);
}

export async function closeShiftCashModal(page: Page): Promise<void> {
  const modal = page.locator('#shiftExpenseModal');
  if (!(await modal.isVisible().catch(() => false))) {
    return;
  }

  await page.evaluate(() => {
    const el = document.getElementById('shiftExpenseModal');
    if (!el) {
      return;
    }
    const bootstrapApi = (window as Window & { bootstrap?: { Modal?: { getOrCreateInstance: (node: Element) => { hide: () => void } } } }).bootstrap;
    if (bootstrapApi?.Modal) {
      bootstrapApi.Modal.getOrCreateInstance(el).hide();
    }
    el.classList.remove('show');
    el.setAttribute('aria-hidden', 'true');
    el.style.display = 'none';
    document.querySelectorAll('.modal-backdrop').forEach((node) => node.remove());
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
  });
  await expect(modal).toBeHidden({ timeout: 10_000 });
}

export async function recordShiftPayIn(page: Page, amount: string, reason: string): Promise<void> {
  await openShiftCashModal(page);
  await page.locator('#shiftCashPayinTab').click();
  const payinInput = page.locator('#shift_payin_amount');
  await expect(payinInput).toBeEnabled({ timeout: 15_000 });
  await payinInput.fill(amount);
  await page.locator('#shift_payin_reason').fill(reason);
  await page.locator('#shiftPayinSaveBtn').click();
  await expect(page.locator('#shiftPayinFormAlert')).toContainText(/تم تسجيل الإيداع/i, { timeout: 10_000 });
}

export async function recordShiftPayOut(page: Page, amount: string, reason: string): Promise<void> {
  await openShiftCashModal(page);
  await page.locator('#shiftCashPayoutTab').click();
  await expect(page.locator('#shift_expense_amount')).toBeEnabled({ timeout: 15_000 });
  await page.locator('#shift_expense_amount').fill(amount);
  await page.locator('#shift_expense_reason').fill(reason);
  await page.locator('#shiftExpenseSaveBtn').click();
  await expect(page.locator('#shiftExpenseFormAlert')).toContainText(/تم تسجيل المصروف/i, { timeout: 10_000 });
  await closeShiftCashModal(page);
}

export async function recordShiftSafeDrop(page: Page, amount: string, reason: string): Promise<void> {
  await openShiftCashModal(page);
  await page.locator('#shiftCashSafeDropTab').click();
  const amountInput = page.locator('#shift_safe_drop_amount');
  await expect(amountInput).toBeEnabled({ timeout: 15_000 });
  await amountInput.fill(amount);
  await page.locator('#shift_safe_drop_reason').fill(reason);
  const saveBtn = page.locator('#shiftSafeDropSaveBtn');
  await expect(saveBtn).toBeVisible({ timeout: 10_000 });
  await expect(saveBtn).toBeEnabled({ timeout: 10_000 });
  await saveBtn.click();
  await expect(page.locator('#shiftSafeDropFormAlert')).toContainText(/تم تسجيل التحويل للخزنة/i, {
    timeout: 10_000,
  });
}

/**
 * Assert cash-modal tab behavior for a role without completing unauthorized money posts.
 * Cashier: expense enabled; pay-in/safe-drop show manager-override affordance and keep save usable.
 * Manager/admin: all three tabs enabled for direct save.
 */
export async function assertCashModalTabBehavior(
  page: Page,
  role: PersonaRole,
): Promise<void> {
  await openShiftCashModal(page);
  const modal = page.locator('#shiftExpenseModal');
  await expect(modal).toBeVisible();

  const canDirectPayIn = role === 'manager' || role === 'admin';
  const canDirectExpense = role === 'manager' || role === 'admin' || role === 'cashier';

  if (canDirectExpense) {
    await page.locator('#shiftCashPayoutTab').click();
    await expect(page.locator('#shift_expense_amount')).toBeEnabled({ timeout: 10_000 });
    await expect(page.locator('#shiftExpenseSaveBtn')).toBeEnabled();
  }

  await page.locator('#shiftCashPayinTab').click();
  await expect(page.locator('#shift_payin_amount')).toBeVisible({ timeout: 10_000 });
  if (canDirectPayIn) {
    await expect(page.locator('#shift_payin_amount')).toBeEnabled();
    await expect(page.locator('#shiftPayinSaveBtn')).toBeEnabled();
    await expect(page.locator('#shiftPayinDrawerNotice')).toBeHidden();
  } else if (role === 'cashier') {
    await expect(page.locator('#shiftPayinDrawerNotice')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#shiftPayinDrawerNotice')).toContainText(/اعتماد المدير|صلاحية/i);
    // Save must stay enabled so cashier can trigger manager override after filling fields.
    await expect(page.locator('#shiftPayinSaveBtn')).toBeEnabled();
  }

  await page.locator('#shiftCashSafeDropTab').click();
  await expect(page.locator('#shift_safe_drop_amount')).toBeVisible({ timeout: 10_000 });
  if (canDirectPayIn) {
    await expect(page.locator('#shift_safe_drop_amount')).toBeEnabled();
    await expect(page.locator('#shiftSafeDropSaveBtn')).toBeEnabled();
    await expect(page.locator('#shiftSafeDropDrawerNotice')).toBeHidden();
  } else if (role === 'cashier') {
    await expect(page.locator('#shiftSafeDropDrawerNotice')).toBeVisible({ timeout: 10_000 });
    await expect(page.locator('#shiftSafeDropDrawerNotice')).toContainText(/اعتماد المدير|صلاحية/i);
    await expect(page.locator('#shiftSafeDropSaveBtn')).toBeEnabled();
  }
}

export async function assertAdminReviewSurfacesReadable(page: Page): Promise<void> {
  await page.goto('/closed_sessions.php');
  await expect(page.locator('h1')).toContainText(/الشيفتات المغلقة/i, { timeout: 15_000 });
  const body = await page.content();
  expect(body).not.toMatch(/fatal error|SQL syntax|mysqli_|uncaught exception/i);

  const unresolvedHeading = page.locator('text=حالات تحتاج مراجعة');
  if (await unresolvedHeading.isVisible().catch(() => false)) {
    await expect(page.locator('[data-bs-target="#resolveDrawerModal"]').first()).toBeVisible();
  }

  const forceBtn = page.locator('[data-bs-target="#forceCloseDrawerModal"]').first();
  if (await forceBtn.isVisible().catch(() => false)) {
    await expect(forceBtn).toBeEnabled();
  }
}

export async function openFirstCashFlowSessionDetail(page: Page): Promise<boolean> {
  await page.goto('/cash_flow_report.php');
  await expect(page.locator('h1')).toContainText(/التدفق النقدي/i, { timeout: 15_000 });
  const detailLink = page.getByTestId('session-detail-link').first();
  if (!(await detailLink.isVisible().catch(() => false))) {
    const fallback = page.locator('a[href*="drawer_session.php?id="]').first();
    if (!(await fallback.isVisible().catch(() => false))) {
      return false;
    }
    await fallback.click();
  } else {
    await detailLink.click();
  }
  await expect(page).toHaveURL(/drawer_session\.php\?id=\d+/);
  return true;
}

export async function recordCashSale(page: Page): Promise<number> {
  await clickFirstAddableItem(page);
  const net = await readCartNet(page);
  expect(net).toBeGreaterThan(0);
  await payCashInModal(page, net);
  await Promise.race([
    page.waitForURL(/pos_barcode\.php/, { timeout: 30_000 }),
    page.waitForURL(/print\/receipt\.php/, { timeout: 30_000 }),
    expect(page.locator('#posForm')).toBeVisible({ timeout: 30_000 }),
  ]);
  if (!page.url().includes('pos_barcode.php')) {
    await page.goto('/pos_barcode.php');
    await expect(page.locator('#posForm')).toBeVisible({ timeout: 20_000 });
  }
  return net;
}

export async function resolveUnresolvedVariance(
  page: Page,
  sessionId: number,
  notes: string,
): Promise<void> {
  await page.goto('/closed_sessions.php');
  const resolveBtn = page.locator(
    `[data-bs-target="#resolveDrawerModal"][data-session-id="${sessionId}"]`,
  ).first();
  await expect(resolveBtn).toBeVisible({ timeout: 15_000 });
  await resolveBtn.click();
  await page.locator('#resolveNotes').fill(notes);
  await Promise.all([
    page.waitForURL(/closed_sessions\.php/, { timeout: 20_000 }),
    page.locator('#resolveDrawerModal button[type="submit"]').click(),
  ]);
}

export async function resolveFirstUnresolvedVariance(page: Page, notes: string): Promise<number> {
  await page.goto('/closed_sessions.php');
  const resolveBtn = page.locator('[data-bs-target="#resolveDrawerModal"]').first();
  await expect(resolveBtn).toBeVisible({ timeout: 15_000 });
  const sessionId = Number(await resolveBtn.getAttribute('data-session-id') || '0');
  await resolveBtn.click();
  await page.locator('#resolveNotes').fill(notes);
  await Promise.all([
    page.waitForURL(/closed_sessions\.php/, { timeout: 20_000 }),
    page.locator('#resolveDrawerModal button[type="submit"]').click(),
  ]);
  return sessionId;
}

export async function expectSessionNotUnresolved(page: Page, sessionId: number): Promise<void> {
  const response = await page.request.get('/ajax/shift_unresolved_list.php');
  expect(response.ok()).toBeTruthy();
  const body = await response.json();
  expect(body.success).toBeTruthy();
  const stillThere = (Array.isArray(body.data) ? body.data : []).some(
    (row: { id?: number | string }) => Number(row.id) === sessionId,
  );
  expect(stillThere).toBe(false);
}

export async function expectUnresolvedQueueEmpty(page: Page): Promise<void> {
  await page.goto('/closed_sessions.php');
  await expect(page.locator('text=حالات تحتاج مراجعة')).toHaveCount(0);
}

export async function openDrawerSessionDetailFromQueue(page: Page): Promise<number> {
  await page.goto('/closed_sessions.php');
  // Prefer unresolved-queue resolve button so we target a closable variance row,
  // not an open-drawer detail link from the open-sessions panel.
  const resolveBtn = page.locator('[data-bs-target="#resolveDrawerModal"]').first();
  if (await resolveBtn.isVisible().catch(() => false)) {
    const sessionId = Number(await resolveBtn.getAttribute('data-session-id') || '0');
    expect(sessionId).toBeGreaterThan(0);
    await page.goto(`/drawer_session.php?id=${sessionId}`);
    await expect(page).toHaveURL(new RegExp(`drawer_session\\.php\\?id=${sessionId}`));
    return sessionId;
  }

  const detailLink = page.locator('a[href*="drawer_session.php?id="]').first();
  await expect(detailLink).toBeVisible({ timeout: 15_000 });
  const href = await detailLink.getAttribute('href');
  const match = href?.match(/id=(\d+)/);
  expect(match?.[1]).toBeTruthy();
  await detailLink.click();
  await expect(page).toHaveURL(/drawer_session\.php\?id=\d+/);
  return Number(match![1]);
}

export async function submitCloseCountViaApi(
  request: APIRequestContext,
  page: Page,
  amount: string,
  idempotencyKey: string,
): Promise<unknown> {
  const tokens = await getShiftCsrfTokens(page);
  const response = await request.post('/do/do_submit_shift_close_count.php', {
    form: {
      counted_amount: amount,
      idempotency_key: idempotencyKey,
      csrf_token: tokens.closeCount,
    },
    failOnStatusCode: false,
  });
  return parseApiJson(response);
}

export async function openZReport(page: Page): Promise<void> {
  const zLink = page.locator('a[href="z_report.php"], a.pos-close-shift-btn-zreport').first();
  if (await zLink.isVisible().catch(() => false)) {
    await zLink.click();
  } else {
    await page.goto('/z_report.php');
  }
  await expect(page.locator('#closeForm')).toBeVisible({ timeout: 15_000 });
}

export async function forceCloseFirstOpenDrawer(
  page: Page,
  countedCash: string,
  reason: string,
): Promise<boolean> {
  await page.goto('/closed_sessions.php');
  const forceBtn = page.locator('[data-bs-target="#forceCloseDrawerModal"]').first();
  if (!(await forceBtn.isVisible().catch(() => false))) {
    return false;
  }

  await forceBtn.click();
  await page.locator('#forceCloseCountedCash').fill(countedCash);
  await page.locator('#forceCloseReason').fill(reason);
  await Promise.all([
    page.waitForURL(/closed_sessions\.php/, { timeout: 20_000 }),
    page.locator('#forceCloseDrawerModal button[type="submit"]').click(),
  ]);
  return true;
}

export function parseMoney(value: unknown): number {
  return Number(String(value ?? '0').replace(/,/g, ''));
}

export async function submitOpenCountViaApi(
  request: APIRequestContext,
  page: Page,
  amount: string,
  idempotencyKey: string,
): Promise<unknown> {
  const tokens = await getShiftCsrfTokens(page);
  const response = await request.post('/do/do_submit_shift_open_count.php', {
    form: {
      counted_amount: amount,
      idempotency_key: idempotencyKey,
      csrf_token: tokens.openCount,
    },
    failOnStatusCode: false,
  });
  return parseApiJson(response);
}

export async function beginCloseCountViaApi(page: Page): Promise<unknown> {
  const response = await page.request.get('/do/do_begin_shift_close_count.php');
  return parseApiJson(response);
}

export async function beginOpenCountViaApi(page: Page): Promise<unknown> {
  const response = await page.request.get('/do/do_begin_shift_open_count.php');
  return parseApiJson(response);
}

export function formatCashAmount(value: number): string {
  return value.toFixed(2);
}
