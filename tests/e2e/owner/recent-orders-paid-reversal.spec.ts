import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import {
  clickFirstAddableItem,
  openRecentOrdersFromCorner,
  payCashInModal,
  readCartNet,
} from '../helpers/pos';

type RecentOrdersPayload = {
  success: boolean;
  orders: Array<{
    id: string | number;
    can_refund?: boolean;
    can_void?: boolean;
    payment_status?: string;
    order_status?: string;
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
  test('admin sees premium reversal modal, options work, and refund succeeds on a fresh paid order', async ({ page }) => {
    await loginAndUnlockPos(page, 'admin');

    await clickFirstAddableItem(page);
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
    await expect(dialog.locator('#paidReversalValidationAlert')).toContainText('يرجى إدخال سبب العملية');

    await reasonField.fill('e2e admin refund smoke');
    await dialog.getByRole('button', { name: 'إلغاء' }).click();
    await expect(dialog).toBeHidden();

    await reversalButton.click();
    await expect(dialog).toBeVisible();
    await actionSelect.selectOption('refund');
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
