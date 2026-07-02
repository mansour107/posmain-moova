import { expect, type Page } from '@playwright/test';

export async function openKdsLauncher(page: Page): Promise<void> {
  await page.goto('/kds.php');
  await expect(page.getByRole('heading', { name: /شاشات المطبخ/ })).toBeVisible({ timeout: 15_000 });
}

export async function openFirstKdsStation(page: Page): Promise<string> {
  await openKdsLauncher(page);
  const stationLink = page.locator('a[href^="kds_station.php?station="]').first();
  await expect(stationLink).toBeVisible({ timeout: 15_000 });
  const href = await stationLink.getAttribute('href');
  expect(href).toBeTruthy();
  await stationLink.click();
  await expect(page.locator('#kdsScreen')).toBeVisible({ timeout: 15_000 });
  return (await page.locator('#kdsScreen').getAttribute('data-station')) || '';
}

export async function waitForKdsPoll(page: Page): Promise<void> {
  const poll = page.waitForResponse(
    (response) =>
      response.url().includes('ajax/kds_tickets_list.php')
      && response.request().method() === 'GET'
      && response.ok(),
    { timeout: 20_000 },
  );
  await poll;
}

export async function expectKdsBoardReady(page: Page): Promise<void> {
  await expect(page.locator('#kdsScreen')).toBeVisible();
  await expect(page.locator('#kdsGrid')).toBeVisible();
  await expect(page.locator('#kdsActiveCount')).toBeVisible();
  await expect(page.locator('#kdsClock')).not.toHaveText('--:--', { timeout: 10_000 });
  await waitForKdsPoll(page);
}

export async function openKdsHistory(page: Page): Promise<void> {
  await page.locator('#kdsHistoryBtn').click();
  await expect(page.locator('#kdsDrawer')).toBeVisible();
  await expect(page.locator('#kdsHistoryList')).toBeVisible();
}

export async function closeKdsHistory(page: Page): Promise<void> {
  await page.locator('#kdsDrawerClose').click();
  await expect(page.locator('#kdsDrawer')).toBeHidden();
}

export function kdsCardForProId(page: Page, proId: number | string) {
  return page.locator(`.kds-card:has(.kds-card__order:text("#${proId}"))`).first();
}

/** @deprecated Prefer kdsCardForProId — the board label is pro_id, not ot_head.id */
export function kdsCardForOrder(page: Page, orderId: number) {
  return kdsCardForProId(page, orderId);
}

export async function startKdsTicket(page: Page, cardLocator: ReturnType<typeof kdsCardForOrder>): Promise<void> {
  const startBtn = cardLocator.locator('.kds-act--start');
  await expect(startBtn).toBeVisible({ timeout: 10_000 });
  const actionResponse = page.waitForResponse((response) =>
    response.url().includes('do/kds_ticket_action.php')
    && response.request().method() === 'POST'
    && response.ok(),
  );
  await startBtn.click();
  const response = await actionResponse;
  const body = await response.json();
  expect(body.success).toBeTruthy();
  await expect(cardLocator).toHaveClass(/status-in_progress/, { timeout: 10_000 });
}

export async function completeKdsTicket(page: Page, cardLocator: ReturnType<typeof kdsCardForOrder>): Promise<string> {
  await expect(cardLocator).toBeVisible({ timeout: 20_000 });
  const ticketId = await cardLocator.getAttribute('data-id');
  expect(ticketId).toBeTruthy();
  const actionResponse = page.waitForResponse((response) =>
    response.url().includes('do/kds_ticket_action.php')
    && response.request().method() === 'POST'
    && response.ok(),
  );
  await cardLocator.locator('.kds-act--done').click();
  const response = await actionResponse;
  const body = await response.json();
  expect(body.success).toBeTruthy();
  await expect(page.locator(`.kds-card[data-id="${ticketId}"]`)).toHaveCount(0, { timeout: 15_000 });
  return ticketId as string;
}
