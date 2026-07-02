import { test, expect } from '@playwright/test';
import { loginAndUnlockPos } from '../helpers/auth';
import {
  clickFirstAddableItem,
  clickNthAddableItem,
  dismissOrderSuccessModal,
  openOrderForEdit,
  readCartNet,
  removeFirstCartLine,
  saveEditedOrder,
  saveTakeawayOrder,
} from '../helpers/pos';
import {
  completeKdsTicket,
  expectKdsBoardReady,
  kdsCardForProId,
  openFirstKdsStation,
  startKdsTicket,
  waitForKdsPoll,
} from '../helpers/kds';

test.describe('kds: POS order edit cases', () => {
  test.beforeEach(async ({ page }) => {
    await loginAndUnlockPos(page, 'admin');
  });

  test('add while ticket is open — same card, new line highlighted', async ({ page }) => {
    await clickFirstAddableItem(page);
    await expect.poll(() => readCartNet(page)).toBeGreaterThan(0);
    const { orderId, proId } = await saveTakeawayOrder(page);
    await dismissOrderSuccessModal(page);

    await openFirstKdsStation(page);
    await expectKdsBoardReady(page);
    const card = kdsCardForProId(page, proId);
    await expect(card).toBeVisible({ timeout: 20_000 });
    const ticketId = await card.getAttribute('data-id');
    expect(ticketId).toBeTruthy();

    await openOrderForEdit(page, orderId);
    await clickNthAddableItem(page, 1);
    await saveEditedOrder(page);

    await page.goto('/kds.php');
    await openFirstKdsStation(page);
    await expectKdsBoardReady(page);
    await waitForKdsPoll(page);

    const sameCard = page.locator(`.kds-card[data-id="${ticketId}"]`);
    await expect(sameCard).toBeVisible({ timeout: 20_000 });
    await expect(sameCard).not.toHaveClass(/kds-card--supplement/);
    await expect(sameCard.locator('.kds-line.is-changed')).toHaveCount(1);
    await expect(sameCard.locator('.kds-line')).toHaveCount(2);
  });

  test('remove while ticket is open — voided line on same card', async ({ page }) => {
    await clickFirstAddableItem(page);
    await clickNthAddableItem(page, 1);
    await expect.poll(async () => page.locator('#itemData .item-card-order').count()).toBeGreaterThanOrEqual(2);
    const { orderId, proId } = await saveTakeawayOrder(page);
    await dismissOrderSuccessModal(page);

    await openFirstKdsStation(page);
    await expectKdsBoardReady(page);
    const card = kdsCardForProId(page, proId);
    await expect(card).toBeVisible({ timeout: 20_000 });
    const ticketId = await card.getAttribute('data-id');

    await openOrderForEdit(page, orderId);
    await removeFirstCartLine(page);
    await saveEditedOrder(page);

    await page.goto('/kds.php');
    await openFirstKdsStation(page);
    await expectKdsBoardReady(page);
    await waitForKdsPoll(page);

    const sameCard = page.locator(`.kds-card[data-id="${ticketId}"]`);
    await expect(sameCard).toBeVisible({ timeout: 20_000 });
    await expect(sameCard.locator('.kds-line.line-voided')).toHaveCount(1);
  });

  test('complete ticket — card leaves the active board', async ({ page }) => {
    await clickFirstAddableItem(page);
    const { proId } = await saveTakeawayOrder(page);
    await dismissOrderSuccessModal(page);

    await openFirstKdsStation(page);
    await expectKdsBoardReady(page);
    const card = kdsCardForProId(page, proId);
    await expect(card).toBeVisible({ timeout: 20_000 });
    await completeKdsTicket(page, card);
    await waitForKdsPoll(page);
    await expect(kdsCardForProId(page, proId)).toHaveCount(0);
  });

  test('remove after complete — no board card', async ({ page }) => {
    await clickFirstAddableItem(page);
    await clickNthAddableItem(page, 1);
    await expect.poll(async () => page.locator('#itemData .item-card-order').count()).toBeGreaterThanOrEqual(2);
    const { orderId, proId } = await saveTakeawayOrder(page);
    await dismissOrderSuccessModal(page);

    await openFirstKdsStation(page);
    await expectKdsBoardReady(page);
    await completeKdsTicket(page, kdsCardForProId(page, proId));

    await openOrderForEdit(page, orderId);
    await removeFirstCartLine(page);
    await saveEditedOrder(page);

    await page.goto('/kds.php');
    await openFirstKdsStation(page);
    await expectKdsBoardReady(page);
    await waitForKdsPoll(page);

    await expect(page.locator(`.kds-card:has(.kds-card__order:text("#${proId}"))`)).toHaveCount(0);
  });

  test('re-save after complete with no edits — board stays empty', async ({ page }) => {
    await clickFirstAddableItem(page);
    const { orderId, proId } = await saveTakeawayOrder(page);
    await dismissOrderSuccessModal(page);

    await openFirstKdsStation(page);
    await expectKdsBoardReady(page);
    await completeKdsTicket(page, kdsCardForProId(page, proId));

    await openOrderForEdit(page, orderId);
    await saveEditedOrder(page);

    await page.goto('/kds.php');
    await openFirstKdsStation(page);
    await expectKdsBoardReady(page);
    await waitForKdsPoll(page);

    await expect(page.locator(`.kds-card:has(.kds-card__order:text("#${proId}"))`)).toHaveCount(0);
  });

  test('add mid-cook — start button offers edit start label', async ({ page }) => {
    await clickFirstAddableItem(page);
    const { orderId, proId } = await saveTakeawayOrder(page);
    await dismissOrderSuccessModal(page);

    await openFirstKdsStation(page);
    await expectKdsBoardReady(page);
    const card = kdsCardForProId(page, proId);
    await expect(card).toBeVisible({ timeout: 20_000 });
    await startKdsTicket(page, card);

    await openOrderForEdit(page, orderId);
    await clickNthAddableItem(page, 1);
    await saveEditedOrder(page);

    await page.goto('/kds.php');
    await openFirstKdsStation(page);
    await expectKdsBoardReady(page);
    await waitForKdsPoll(page);

    const cookingCard = kdsCardForProId(page, proId);
    await expect(cookingCard).toBeVisible({ timeout: 20_000 });
    await expect(cookingCard.locator('.kds-act--start')).toContainText('بدء التعديل');
    await expect(cookingCard.locator('.kds-line.is-changed')).toHaveCount(1);
  });
});
