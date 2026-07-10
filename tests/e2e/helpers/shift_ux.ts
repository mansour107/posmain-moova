import fs from 'node:fs';
import path from 'node:path';
import { expect, type Locator, type Page } from '@playwright/test';
import { assertNoFatalText } from './auth';

const campaignRunId =
  process.env.POSMAIN_QA_RUN_ID
  || process.env.POSMAIN_SHIFT_CAMPAIGN_RUN_ID
  || new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);

export function shiftCampaignScreenshotDir(): string {
  return path.join(process.cwd(), 'tests/e2e/screenshots/shift-campaign', campaignRunId);
}

function parseCssColor(value: string): { r: number; g: number; b: number; a: number } | null {
  const match = value.match(/rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)(?:\s*,\s*([\d.]+))?\s*\)/i);
  if (!match) {
    return null;
  }
  return {
    r: Number(match[1]),
    g: Number(match[2]),
    b: Number(match[3]),
    a: match[4] === undefined ? 1 : Number(match[4]),
  };
}

function relativeLuminance(r: number, g: number, b: number): number {
  const channel = (c: number) => {
    const s = c / 255;
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
  };
  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

function contrastRatio(fg: string, bg: string): number | null {
  const foreground = parseCssColor(fg);
  const background = parseCssColor(bg);
  if (!foreground || !background || foreground.a < 0.4) {
    return null;
  }
  const l1 = relativeLuminance(foreground.r, foreground.g, foreground.b);
  const l2 = relativeLuminance(background.r, background.g, background.b);
  const lighter = Math.max(l1, l2);
  const darker = Math.min(l1, l2);
  return (lighter + 0.05) / (darker + 0.05);
}

/**
 * Walk up ancestors until an opaque-enough background is found.
 */
async function effectiveBackgroundColor(locator: Locator): Promise<string> {
  return locator.evaluate((el) => {
    let node: HTMLElement | null = el as HTMLElement;
    while (node) {
      const style = getComputedStyle(node);
      const bg = style.backgroundColor;
      const parsed = bg.match(/rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)(?:\s*,\s*([\d.]+))?\s*\)/i);
      if (parsed) {
        const alpha = parsed[4] === undefined ? 1 : Number(parsed[4]);
        if (alpha >= 0.85) {
          return bg;
        }
      }
      node = node.parentElement;
    }
    return getComputedStyle(document.body).backgroundColor || 'rgb(255, 255, 255)';
  });
}

export async function captureShiftShot(page: Page, name: string): Promise<string> {
  const dir = shiftCampaignScreenshotDir();
  fs.mkdirSync(dir, { recursive: true });
  const safeName = name.replace(/[^\w.-]+/g, '_');
  const filePath = path.join(dir, `${safeName}.png`);
  await page.screenshot({ path: filePath, fullPage: false });
  return filePath;
}

export async function assertReadableText(
  locator: Locator,
  options: { minRatio?: number; label?: string } = {},
): Promise<void> {
  const minRatio = options.minRatio ?? 3.0;
  await expect(locator, options.label || 'text target').toBeVisible({ timeout: 10_000 });
  const color = await locator.evaluate((el) => getComputedStyle(el).color);
  const background = await effectiveBackgroundColor(locator);
  const ratio = contrastRatio(color, background);
  if (ratio === null) {
    return;
  }
  expect(
    ratio,
    `${options.label || 'text'} contrast ${ratio.toFixed(2)} (fg=${color}, bg=${background})`,
  ).toBeGreaterThanOrEqual(minRatio);
}

export async function assertModalUsable(
  modal: Locator,
  options: { primaryAction?: Locator; title?: Locator } = {},
): Promise<void> {
  await expect(modal).toBeVisible({ timeout: 10_000 });
  const display = await modal.evaluate((el) => getComputedStyle(el).display);
  expect(display).not.toBe('none');
  const opacity = Number(await modal.evaluate((el) => getComputedStyle(el).opacity));
  expect(opacity).toBeGreaterThan(0.2);

  if (options.title) {
    await expect(options.title).toBeVisible();
    await assertReadableText(options.title, { label: 'modal title' });
  }

  if (options.primaryAction) {
    await expect(options.primaryAction).toBeVisible();
  }

  const html = await modal.evaluate((el) => el.innerHTML);
  assertNoFatalText(html);
}

export async function assertNoJargonNoise(
  page: Page,
  forbiddenPatterns: RegExp[],
): Promise<void> {
  const body = await page.locator('body').innerText();
  for (const pattern of forbiddenPatterns) {
    expect(body, `jargon pattern ${pattern}`).not.toMatch(pattern);
  }
}

export async function assertPageHealthy(page: Page, heading?: RegExp): Promise<void> {
  const body = await page.content();
  assertNoFatalText(body);
  if (heading) {
    await expect(page.locator('h1').first()).toContainText(heading);
  }
}

export async function assertStatusPillsReadable(page: Page): Promise<void> {
  const pills = page.locator('.pr-pill, .badge, [class*="status-pill"], .psh-variance');
  const count = await pills.count();
  const sample = Math.min(count, 6);
  for (let i = 0; i < sample; i += 1) {
    const pill = pills.nth(i);
    if (await pill.isVisible().catch(() => false)) {
      const text = (await pill.innerText()).trim();
      if (text.length > 0) {
        await assertReadableText(pill, { label: `status pill: ${text.slice(0, 24)}`, minRatio: 2.5 });
      }
    }
  }
}
