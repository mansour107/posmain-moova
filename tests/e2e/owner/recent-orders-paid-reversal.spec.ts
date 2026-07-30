import { test, expect } from '@playwright/test';
import { execFileSync, execSync } from 'child_process';
import path from 'path';
import { loginAndUnlockPos } from '../helpers/auth';
import {
  clickFirstAddableItem,
  openRecentOrdersFromCorner,
  payCashInModal,
  readCartNet,
} from '../helpers/pos';

test.beforeEach(() => {
  const root = path.join(__dirname, '../../..');
  try {
    execSync('docker inspect posmain-php >/dev/null 2>&1', { stdio: 'pipe' });
    execSync(
      'docker exec -e POSMAIN_BRANCH_WORKER_AUTODISPATCH=0 posmain-php php /app/cli/seed_security_fixtures.php',
      { cwd: root, stdio: 'pipe' },
    );
    return;
  } catch {
    // Fall back to host PHP when the Docker E2E stack is not running.
  }
  execSync('php cli/seed_security_fixtures.php', {
    cwd: root,
    env: { ...process.env, POSMAIN_DB_NAME: process.env.POSMAIN_DB_NAME || 'kody2' },
    stdio: 'pipe',
  });
});

type RecentOrdersPayload = {
  success: boolean;
  orders: Array<{
    id: string | number;
    can_refund?: boolean;
    can_void?: boolean;
    refund_eligible?: boolean;
    void_eligible?: boolean;
    payment_status?: string;
    order_status?: string;
    mutation_version?: string | number;
    refunded_amount?: string | number;
    remaining_refundable_amount?: string | number;
    reversal_status?: string;
    refundable_lines?: Array<{
      original_detail_id: string | number;
      remaining_quantity: string | number;
      remaining_amount: string | number;
    }>;
  }>;
  refund_tenders?: Array<{
    code: string;
    type: string;
  }>;
};

async function fetchRecentOrders(page): Promise<RecentOrdersPayload> {
  const response = await page.request.get('/ajax/get_recent_orders.php?limit=30');
  expect(response.status()).toBeLessThan(500);
  return response.json();
}

async function postRefundOrder(
  page,
  payload: Record<string, string>,
): Promise<{ status: number; body: Record<string, unknown> | null }> {
  return page.evaluate(async (data) => {
    const tokenEl = document.querySelector('meta[name="posmain-csrf-token"]');
    const csrf = tokenEl?.getAttribute('content') || '';
    const body = new URLSearchParams(data);
    const response = await fetch('/ajax/refund_order.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-Token': csrf,
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body.toString(),
    });
    const json = await response.json().catch(() => null);
    return { status: response.status, body: json };
  }, payload);
}

function assertNoReversalCapabilities(payload: RecentOrdersPayload): void {
  expect(payload.success).toBe(true);
  for (const order of payload.orders || []) {
    if (order.payment_status === 'paid' && order.order_status === 'completed') {
      expect(order.refund_eligible || order.can_refund).toBeTruthy();
      expect(order.void_eligible || order.can_void).toBeTruthy();
    }
  }
}

function enableLocalCardRefundTender(): void {
  execFileSync('docker', [
    'exec',
    'posmain-mysql',
    'mariadb',
    '-uroot',
    'kody2',
    '-e',
    "UPDATE payment_methods SET account_id = 39 WHERE code = 'p6_card' AND account_id IS NULL",
  ], { stdio: 'pipe' });
}

test.describe('owner: paid order reversal access control', () => {
  test('cashier sees locked reversal controls and refund requires manager approval', async ({ page }) => {
    await loginAndUnlockPos(page, 'cashier');

    const payload = await fetchRecentOrders(page);
    assertNoReversalCapabilities(payload);

    await openRecentOrdersFromCorner(page);
    const paidRows = page.locator('#recentOrdersList tr[data-order-id] .reverse-paid-order');
    const paidCount = await paidRows.count();
    if (paidCount > 0) {
      await expect(paidRows.first()).toHaveClass(/pos-action-locked/);
    }

    const denied = await postRefundOrder(page, {
      order_id: String(payload.orders?.[0]?.id || '1'),
      mutation_version: String(payload.orders?.[0]?.mutation_version || '1'),
      action: 'refund',
      refund_stock_policy: 'waste',
      reason: 'cashier should require approval',
      idempotency_key: 'e2e-cashier-refund-denied',
    });
    expect([403, 400]).toContain(denied.status);
    expect(String(denied.body?.code || denied.body?.message || '')).toMatch(/MANAGER_APPROVAL_REQUIRED|APPROVAL/i);
  });

  test('manager sees locked reversal controls and refund requires manager approval', async ({ page }) => {
    await loginAndUnlockPos(page, 'manager');

    const payload = await fetchRecentOrders(page);
    assertNoReversalCapabilities(payload);

    await openRecentOrdersFromCorner(page);
    const paidRows = page.locator('#recentOrdersList tr[data-order-id] .reverse-paid-order');
    const paidCount = await paidRows.count();
    if (paidCount > 0) {
      await expect(paidRows.first()).toHaveClass(/pos-action-locked/);
    }

    const denied = await postRefundOrder(page, {
      order_id: String(payload.orders?.[0]?.id || '1'),
      mutation_version: String(payload.orders?.[0]?.mutation_version || '1'),
      action: 'refund',
      refund_stock_policy: 'waste',
      reason: 'manager should require approval',
      idempotency_key: 'e2e-manager-refund-denied',
    });
    expect([403, 400]).toContain(denied.status);
    expect(String(denied.body?.code || '')).toMatch(/MANAGER_APPROVAL_REQUIRED|APPROVAL/i);
  });
});

test.describe('owner: paid order reversal admin flow', () => {
  test('admin completes successive item and amount partial refunds with persisted balances and tender states', async ({ page }) => {
    // The generic security fixture deliberately leaves non-cash methods as
    // unconfigured drafts. This local-only test binds its seeded card method
    // to the fixture's default bank account so pending_external can be proven
    // through the cashier UI without changing application defaults.
    enableLocalCardRefundTender();
    await loginAndUnlockPos(page, 'admin');

    for (let index = 0; index < 2; index += 1) {
      await clickFirstAddableItem(page);
      const sugarConfirm = page.locator('#sugarSpoonsConfirm');
      if (await sugarConfirm.isVisible().catch(() => false)) {
        await sugarConfirm.click();
      }
    }
    await expect.poll(() => readCartNet(page), {
      message: 'two selected POS units should update the payable total',
      timeout: 10_000,
    }).toBeGreaterThan(0);
    const net = await readCartNet(page);

    const paymentResponse = page.waitForResponse((response) =>
      response.url().includes('api/pos/index.php')
      && response.request().method() === 'POST',
    );
    await Promise.all([
      page.waitForURL(/print\/receipt\.php/, { timeout: 30_000 }),
      payCashInModal(page, net),
    ]);
    const paymentBody = await (await paymentResponse).json();
    const orderId = Number(paymentBody.order_id);
    expect(orderId).toBeGreaterThan(0);

    await page.goto('/pos_barcode.php');
    await expect(page.locator('#posForm')).toBeVisible({ timeout: 20_000 });
    await openRecentOrdersFromCorner(page);

    const row = page.locator(`#recentOrdersList tr[data-order-id="${orderId}"]`);
    await expect(row).toBeVisible({ timeout: 20_000 });
    await row.locator('.reverse-paid-order').click();

    const dialog = page.locator('#paidOrderReversalModal');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('#paid-reversal-balance-summary')).toContainText('المتبقي');
    const itemRows = dialog.locator('#paid-reversal-items-list tr[data-refund-detail-id]');
    await expect(itemRows).not.toHaveCount(0);

    const modeSelect = dialog.locator('#paid-reversal-refund-mode');
    await modeSelect.selectOption('items');
    const firstItemRow = itemRows.first();
    await firstItemRow.locator('.paid-reversal-line-check').check();
    const quantityInput = firstItemRow.locator('.paid-reversal-line-qty');
    await expect(quantityInput).toBeEnabled();
    await quantityInput.fill('1.000000');
    await dialog.locator('#paid-reversal-tender').selectOption('cash');
    await dialog.locator('#paid-reversal-policy').selectOption('waste');
    await dialog.locator('#paid-reversal-reason').fill('e2e admin item partial refund');

    const itemRefundResponse = page.waitForResponse((response) =>
      response.url().includes('/ajax/refund_order.php')
      && response.request().method() === 'POST',
    );
    await dialog.locator('#paidReversalSubmitBtn').click();
    const itemResponse = await itemRefundResponse;
    const itemBody = await itemResponse.json();
    expect(itemResponse.ok(), JSON.stringify(itemBody)).toBeTruthy();
    expect(itemBody.success).toBeTruthy();
    expect(itemBody.data?.refund_mode).toBe('items');
    expect(itemBody.data?.reversal_status).toBe('partial');
    expect(itemBody.data?.payment_status).toBe('paid');
    expect(Number(itemBody.data?.refund_amount)).toBeGreaterThan(0);
    expect(Number(itemBody.data?.remaining_refundable_amount)).toBeGreaterThan(0);
    expect(Number(itemBody.data?.drawer_session_id)).toBeGreaterThan(0);
    await expect(dialog).toBeHidden({ timeout: 10_000 });

    const partialRow = page.locator(`#recentOrdersList tr[data-order-id="${orderId}"]`);
    await expect(partialRow).toContainText('مسترد جزئياً', { timeout: 15_000 });
    await expect(partialRow.locator('.reverse-paid-order')).toBeVisible();
    await partialRow.locator('.reverse-paid-order').click();
    await expect(dialog).toBeVisible();

    await modeSelect.selectOption('amount');
    await dialog.locator('#paid-reversal-amount').fill('1.00');
    const tenderSelect = dialog.locator('#paid-reversal-tender');
    const nonCashTender = await tenderSelect.locator('option').evaluateAll((options) => {
      const match = options
        .map((option) => option as HTMLOptionElement)
        .find((option) =>
          option.value !== '' && option.value !== 'cash' && option.dataset.type !== 'cash',
        );
      return match?.value || '';
    });
    expect(nonCashTender).not.toBe('');
    await tenderSelect.selectOption(nonCashTender);
    await dialog.locator('#paid-reversal-reference').fill('');
    await expect(dialog.locator('#paid-reversal-settlement-hint')).toContainText('معلقة');
    await dialog.locator('#paid-reversal-reason').fill('e2e admin amount partial refund');

    const amountRefundResponse = page.waitForResponse((response) =>
      response.url().includes('/ajax/refund_order.php')
      && response.request().method() === 'POST',
    );
    await dialog.locator('#paidReversalSubmitBtn').click();
    const amountResponse = await amountRefundResponse;
    const amountBody = await amountResponse.json();
    expect(amountResponse.ok(), JSON.stringify(amountBody)).toBeTruthy();
    expect(amountBody.success, JSON.stringify(amountBody)).toBeTruthy();
    expect(amountBody.data?.refund_mode).toBe('amount');
    expect(amountBody.data?.refund_amount).toBe('1.00');
    expect(amountBody.data?.pending_external_amount).toBe('1.00');
    expect(amountBody.data?.reversal_status).toBe('partial');

    const persisted = await fetchRecentOrders(page);
    const persistedOrder = persisted.orders.find((order) => Number(order.id) === orderId);
    expect(persistedOrder).toBeTruthy();
    expect(persistedOrder?.payment_status).toBe('paid');
    expect(persistedOrder?.reversal_status).toBe('partial');
    expect(Number(persistedOrder?.refunded_amount)).toBeCloseTo(
      Number(itemBody.data?.refund_amount) + 1,
      2,
    );
    expect(Number(persistedOrder?.remaining_refundable_amount)).toBeCloseTo(
      Number(itemBody.data?.remaining_refundable_amount) - 1,
      2,
    );
    expect(persistedOrder?.refundable_lines?.length).toBeGreaterThan(0);
  });

  test('admin sees premium reversal modal, options work, and refund succeeds on a fresh paid order', async ({ page }) => {
    await loginAndUnlockPos(page, 'admin');

    await clickFirstAddableItem(page);
    const sugarConfirm = page.locator('#sugarSpoonsConfirm');
    if (await sugarConfirm.isVisible().catch(() => false)) {
      await sugarConfirm.click();
    }
    await expect.poll(() => readCartNet(page), {
      message: 'selected POS item should update the payable total',
      timeout: 10_000,
    }).toBeGreaterThan(0);
    const net = await readCartNet(page);
    expect(net).toBeGreaterThan(0);

    const paymentResponse = page.waitForResponse((response) =>
      response.url().includes('api/pos/index.php')
      && response.request().method() === 'POST',
    );

    await Promise.all([
      page.waitForURL(/print\/receipt\.php/, { timeout: 30_000 }),
      payCashInModal(page, net),
    ]);

    const paymentBody = await (await paymentResponse).json();
    const orderId = Number(paymentBody.order_id);
    const proId = Number(paymentBody.pro_id);
    expect(orderId).toBeGreaterThan(0);
    expect(proId).toBeGreaterThan(0);

    await page.goto('/pos_barcode.php');
    await expect(page.locator('#posForm')).toBeVisible({ timeout: 20_000 });

    await openRecentOrdersFromCorner(page);
    const row = page.locator(`#recentOrdersList tr[data-order-id="${orderId}"]`);
    await expect(row).toBeVisible({ timeout: 20_000 });
    const reversalButton = row.locator('.reverse-paid-order');
    await expect(reversalButton).toBeVisible();
    await reversalButton.click();

    const dialog = page.locator('#paidOrderReversalModal');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('label[for="paid-reversal-reason"]')).toBeVisible();
    await expect(dialog.locator('#paid-reversal-original-tenders')).toContainText('طرق الدفع الأصلية');

    const tenderSelect = dialog.locator('#paid-reversal-tender');
    await expect(tenderSelect).toBeVisible();
    await expect(tenderSelect.locator('option')).not.toHaveCount(1);
    await tenderSelect.selectOption('cash');
    await expect(tenderSelect).toHaveValue('cash');
    await expect(dialog.locator('#paid-reversal-settlement-hint')).toContainText('جلسة الدرج المفتوحة');

    const reasonField = dialog.locator('#paid-reversal-reason');
    await expect(reasonField).toBeEditable();
    await reasonField.click();
    await reasonField.fill('typing check');
    await expect(reasonField).toHaveValue('typing check');
    await reasonField.fill('');

    const actionSelect = dialog.locator('#paid-reversal-action');
    await expect(actionSelect).toBeVisible();
    await expect(actionSelect.locator('option[value="refund"]')).toHaveCount(1);
    await expect(actionSelect.locator('option[value="void"]')).toHaveCount(1);

    const policySelect = dialog.locator('#paid-reversal-policy');
    await policySelect.selectOption('return_to_stock');
    await expect(policySelect).toHaveValue('return_to_stock');
    await policySelect.selectOption('waste');
    await expect(policySelect).toHaveValue('waste');

    await dialog.locator('#paidReversalSubmitBtn').click();
    await expect(dialog.locator('#paidReversalValidationAlert')).toBeVisible();
    await expect(dialog.locator('#paidReversalValidationAlert')).toContainText('اكتب سبب الاسترداد أو الإلغاء قبل المتابعة.');

    await reasonField.fill('e2e admin refund smoke');
    await dialog.getByRole('button', { name: 'إلغاء' }).click();
    await expect(dialog).toBeHidden();

    await reversalButton.click();
    await expect(dialog).toBeVisible();
    await actionSelect.selectOption('refund');
    await tenderSelect.selectOption('cash');
    await policySelect.selectOption('waste');
    await reasonField.fill('e2e admin refund smoke');

    const refundResponse = page.waitForResponse((response) =>
      response.url().includes('/ajax/refund_order.php')
      && response.request().method() === 'POST',
    );
    await dialog.locator('#paidReversalSubmitBtn').click();
    const response = await refundResponse;
    const body = await response.json();
    expect(response.ok(), JSON.stringify(body)).toBeTruthy();
    expect(body.success).toBeTruthy();
    expect(body.data?.payment_status || body.payment_status).toBe('refunded');

    await expect(dialog).toBeHidden({ timeout: 10_000 });

    const updatedRow = page.locator(`#recentOrdersList tr[data-order-id="${orderId}"]`);
    await expect(updatedRow).toContainText('مسترد', { timeout: 15_000 });
    await expect(updatedRow.locator('.reverse-paid-order')).toHaveCount(0);
    await expect(updatedRow).toContainText(String(proId));
  });
});
