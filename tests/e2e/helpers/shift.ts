import { expect, type Page } from '@playwright/test';

export async function fillCloseShiftForm(
  page: Page,
  values: { amount?: string; notes?: string } = {},
): Promise<void> {
  const amount = values.amount ?? '0';

  const nextBtn = page.locator('[data-psh-close-next]');
  if (await nextBtn.isVisible().catch(() => false)) {
    await nextBtn.click();
  }

  await expect(page.locator('#pshCloseAmount')).toBeVisible({ timeout: 10_000 });
  await page.locator('#pshCloseAmount').fill(amount);

  if (values.notes) {
    const notesField = page.locator('#shift_notes');
    if (await notesField.isVisible({ timeout: 1_000 }).catch(() => false)) {
      await notesField.fill(values.notes);
    }
  }

  const submitCount = page.locator('[data-psh-close-submit-count]');
  await expect(submitCount).toBeVisible({ timeout: 10_000 });
  await expect(submitCount).toBeEnabled({ timeout: 10_000 });

  const countResponsePromise = page.waitForResponse(
    (response) => response.url().includes('do_submit_shift_close_count.php')
      && response.request().method() === 'POST',
    { timeout: 20_000 },
  );
  await submitCount.click();
  const countBody = await (await countResponsePromise).json().catch(() => null) as {
    data?: { status?: string };
  } | null;

  if (countBody?.data?.status === 'recount') {
    await page.locator('#pshCloseAmount').fill(amount);
    await expect(submitCount).toBeEnabled({ timeout: 10_000 });
    const recountPromise = page.waitForResponse(
      (response) => response.url().includes('do_submit_shift_close_count.php')
        && response.request().method() === 'POST',
      { timeout: 20_000 },
    );
    await submitCount.click();
    await recountPromise;
  }

  const finalBtn = page.locator('[data-psh-close-final]');
  if (await finalBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await finalBtn.click();
  }
}

export async function fillOpenShiftCount(
  page: Page,
  amount: string,
): Promise<void> {
  const overlay = page.locator('#pshOpenOverlay');
  await overlay.waitFor({ state: 'visible', timeout: 15_000 });

  // Branch blocked / baseline required — leave overlay for the caller to handle.
  if (await page.locator('#pshOpenBranchBlocked').isVisible().catch(() => false)) {
    return;
  }
  if (await page.locator('#pshOpenBaselineRequired').isVisible().catch(() => false)) {
    return;
  }

  for (let attempt = 1; attempt <= 2; attempt++) {
    await page.locator('#pshOpenAmount').fill(amount);
    const responsePromise = page.waitForResponse(
      (response) => response.url().includes('do_submit_shift_open_count.php')
        && response.request().method() === 'POST',
      { timeout: 20_000 },
    );
    await page.locator('[data-psh-open-submit]').click();
    const body = await (await responsePromise).json().catch(() => null) as {
      success?: boolean;
      error?: string;
      data?: { status?: string };
    } | null;

    if (!body?.success) {
      if (body?.error === 'OPENING_BASELINE_REQUIRED') {
        return;
      }
      const deniedCode = String((body as { code?: string } | null)?.code || body?.error || '');
      if (/PERMISSION_DENIED/i.test(deniedCode)) {
        // Roles without pos.shift.open (e.g. waiter) stay on the overlay.
        return;
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
