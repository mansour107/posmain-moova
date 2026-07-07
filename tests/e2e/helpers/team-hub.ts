import { expect, type Page } from '@playwright/test';

export async function openTeamHubRolePermissions(page: Page, roleLabel: string): Promise<void> {
  await page.goto('/team.php?tab=roles', { waitUntil: 'domcontentloaded' });
  const card = page.locator('#rolesGrid .team-hub-card').filter({ hasText: roleLabel }).first();
  await expect(card).toBeVisible({ timeout: 15_000 });
  await card.click();
  await expect(page.locator('#teamPanel.is-open')).toBeVisible({ timeout: 10_000 });
  await expect(page.locator('.team-hub-accordion summary').first()).toBeVisible();
  await expect(page.locator('.team-hub-switch').first()).toBeVisible();
}

export async function openTeamHubStaffPermissions(page: Page, staffMatch: string): Promise<void> {
  await page.goto('/team.php?tab=staff', { waitUntil: 'domcontentloaded' });
  const card = page.locator('#staffGrid .team-hub-card').filter({ hasText: staffMatch }).first();
  await expect(card).toBeVisible({ timeout: 15_000 });
  await card.click();
  await expect(page.locator('#teamPanel.is-open')).toBeVisible({ timeout: 10_000 });
  await page.locator('[data-staff-tab="permissions"]').click();
  await expect(page.locator('#staffTabOverrides .team-hub-accordion').first()).toBeVisible({ timeout: 10_000 });
  await expect(page.locator('input[data-perm]').first()).toBeAttached();
}

export async function findSoleOwnerLikeStaffId(page: Page): Promise<string | null> {
  await page.goto('/team.php?tab=staff', { waitUntil: 'domcontentloaded' });
  const cards = page.locator('#staffGrid .team-hub-card--person');
  const count = await cards.count();
  const ownerIds: string[] = [];
  for (let i = 0; i < count; i++) {
    const card = cards.nth(i);
    const roleText = (await card.locator('.team-hub-chip').textContent()) || '';
    if (/مالك|owner/i.test(roleText)) {
      const id = await card.getAttribute('data-staff-id');
      if (id) ownerIds.push(id);
    }
  }
  return ownerIds.length === 1 ? ownerIds[0] : null;
}
