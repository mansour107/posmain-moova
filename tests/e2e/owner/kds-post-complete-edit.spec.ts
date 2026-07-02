import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import {
  clickFirstAddableItem,
  dismissOrderSuccessModal,
  readCartNet,
  saveOrderOnly,
} from '../helpers/pos';
import {
  completeKdsTicket,
  expectKdsBoardReady,
  kdsCardForProId,
  openFirstKdsStation,
  waitForKdsPoll,
} from '../helpers/kds';

/**
 * After a ticket is completed on KDS, reopening the order in POS and saving
 * must not resend the whole order. Only net-new items should appear as a
 * supplement card with a single delta line.
 */
test.describe('kds: post-complete POS edit sends delta only', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndUnlockPos(page, 'admin');
  });

  test('complete on KDS, reopen POS, add one item — supplement shows one line only', async ({ page }) => {
    const netBefore = await readCartNet(page);
    await clickFirstAddableItem(page);
    await expect.poll(() => readCartNet(page)).toBeGreaterThan(netBefore);

    const saveResponse = page.waitForResponse((response) =>
      response.url().includes('api/pos/index.php')
      && response.request().method() === 'POST'
      && response.url().includes('route=orders.takeaway'),
    );
    await saveOrderOnly(page);
    const response = await saveResponse;
    const body = await response.json();
    expect(body.success).toBeTruthy();
    const orderId = Number(body.order_id);
    const proId = Number(body.pro_id);
    expect(orderId).toBeGreaterThan(0);
    expect(proId).toBeGreaterThan(0);
    await dismissOrderSuccessModal(page);

    await openFirstKdsStation(page);
    await expectKdsBoardReady(page);

    const card = kdsCardForProId(page, proId);
    await expect.poll(async () => card.count(), { timeout: 20_000 }).toBeGreaterThan(0);
    await completeKdsTicket(page, card);

    await page.goto(`/pos_barcode.php?edit=${orderId}`);
    await expect(page.locator('#itemData .item-card-order')).not.toHaveCount(0, { timeout: 20_000 });

    const netOnEdit = await readCartNet(page);
    await clickFirstAddableItem(page);
    await expect.poll(() => readCartNet(page)).toBeGreaterThan(netOnEdit);

    const editSave = page.waitForResponse((response) =>
      response.url().includes('api/pos/index.php')
      && response.request().method() === 'POST'
      && (response.url().includes('route=orders.edit') || response.url().includes('route=orders.takeaway')),
    );
    await saveOrderOnly(page);
    const editResponse = await editSave;
    const editBody = await editResponse.json();
    expect(editBody.success).toBeTruthy();

    await page.goto('/kds.php');
    await openFirstKdsStation(page);
    await expectKdsBoardReady(page);
    await waitForKdsPoll(page);

    const supplement = page.locator(`.kds-card--supplement:has(.kds-card__order:text("#${proId}"))`).first();
    await expect(supplement).toBeVisible({ timeout: 20_000 });

    const actionableLines = supplement.locator('.kds-line:not(.line-voided):not(.line-done)');
    await expect(actionableLines).toHaveCount(1);

    const rootCards = page.locator(`.kds-card:not(.kds-card--supplement):has(.kds-card__order:text("#${proId}"))`);
    await expect(rootCards).toHaveCount(0);
  });
});
