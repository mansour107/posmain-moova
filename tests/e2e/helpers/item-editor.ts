import { expect, type Page } from '@playwright/test';
import { execSync } from 'node:child_process';

export type ItemEditorType = 'sellable' | 'made' | 'ingredient' | 'packaging' | 'service';

/** Known units in the local focushouse fixture DB. */
export const ITEM_EDITOR_UNITS = {
  piece: '1',
  kg: '7',
  carton: '2',
} as const;

export interface SellProfileOptions {
  unitId?: string;
  price1: string;
  cost?: string;
}

export interface PurchaseProfileOptions {
  storageUnitId?: string;
  purchaseUnitId?: string;
  purchaseStorageFactor?: string;
  sellStorageFactor?: string;
  cost?: string;
  purchaseBarcode?: string;
}

export interface CreateItemProfile {
  name: string;
  barcode: string;
  type: ItemEditorType;
  sell?: SellProfileOptions;
  sellActive?: boolean;
  purchase?: PurchaseProfileOptions;
  purchaseActive?: boolean;
}

export function uniqueItemLabel(prefix: string): { name: string; barcode: string } {
  const numeric = `${Date.now() % 1_000_000_000}${Math.floor(Math.random() * 1000)}`.padStart(12, '0');
  return {
    name: `E2E ${prefix} ${numeric}`,
    barcode: numeric.slice(0, 12),
  };
}

export async function openAddItemEditor(page: Page): Promise<void> {
  const response = await page.goto('/add_item.php');
  expect(response?.status() ?? 0).toBeLessThan(500);
  await expect(page.locator('#item-main-form')).toBeVisible();
  await expect(page.locator('#item-type-section')).toBeVisible();
}

export async function selectItemType(page: Page, type: ItemEditorType): Promise<void> {
  const current = await page.locator('#item_type').inputValue();
  if (current !== type) {
    await page.locator(`.item-type-choice[data-item-type="${type}"]`).click();
    await expect(page.locator('#item_type')).toHaveValue(type);
  }
}

export async function selectItemUnit(_page: Page, _fieldId: string, _unitId: string): Promise<void> {
  // Unit pickers were removed from the item editor UI; units default via hidden fields.
}

export async function setSellSectionActive(page: Page, active: boolean): Promise<void> {
  const toggle = page.locator('#sell_section_checkbox');
  if (await toggle.count()) {
    const checked = await toggle.isChecked();
    if (checked !== active) {
      await toggle.setChecked(active);
    }
  }
}

export async function setPurchaseSectionActive(_page: Page, _active: boolean): Promise<void> {
  // Purchase section removed from the item editor UI.
}

export async function fillSellProfile(page: Page, sell: SellProfileOptions): Promise<void> {
  await page.locator('#sell_price1').fill(sell.price1);
  if (sell.cost !== undefined) {
    await page.locator('#direct_cost_price').fill(sell.cost);
  }
}

export async function fillPurchaseProfile(_page: Page, _purchase: PurchaseProfileOptions): Promise<void> {
  // Purchase section removed from the item editor UI.
}

export async function fillCreateItemForm(page: Page, profile: CreateItemProfile): Promise<void> {
  await openAddItemEditor(page);
  await page.locator('#iname').fill(profile.name);
  await page.locator('input.frst[name="barcode"]').fill(profile.barcode);
  await selectItemType(page, profile.type);

  if (profile.type === 'sellable' || profile.type === 'made' || profile.type === 'service') {
    await fillSellProfile(page, profile.sell ?? { price1: '10' });
  } else {
    if (profile.sellActive === false) {
      await setSellSectionActive(page, false);
    } else if (profile.sellActive) {
      await setSellSectionActive(page, true);
      await fillSellProfile(page, profile.sell ?? { price1: '10' });
    }
  }
}

export async function saveItem(page: Page): Promise<void> {
  await expect(page.locator('#iname')).toHaveValue(/.+/);
  const saveButton = page.locator('.item-editor-actions button[type="submit"]');
  await saveButton.scrollIntoViewIfNeeded();
  await Promise.all([
    page.waitForURL(/add_item\.php(?:\?|$)/, { timeout: 45_000 }),
    saveButton.click(),
  ]);
  await expect(page.locator('#iname')).toHaveValue('');
}

export async function findItemRowInCatalog(page: Page, needle: string) {
  await page.goto('/myitems.php');
  await page.locator('#search').fill(needle);
  await page.waitForTimeout(300);
  const row = page.locator(`tr.catalog-row[data-search*="${needle}"]`).first();
  await expect(row, `catalog row for ${needle}`).toBeVisible({ timeout: 15_000 });
  return row;
}

export async function openItemEditFromCatalog(page: Page, needle: string): Promise<void> {
  const row = await findItemRowInCatalog(page, needle);
  const editUrl = await row.getAttribute('data-edit-url');
  expect(editUrl, 'catalog row should expose edit url').toBeTruthy();
  await page.goto(editUrl!);
  await expect(page.locator('#item-main-form')).toBeVisible();
  await expect(page.locator('#iname')).toHaveValue(new RegExp(needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
}

export function queryLocalDb(sql: string): string {
  const db = process.env.POSMAIN_E2E_DB_NAME || 'focushouse';
  const escaped = sql.replace(/"/g, '\\"');
  return execSync(
    `docker exec posmain-mysql mariadb -uroot ${db} -N -B -e "${escaped}"`,
    { encoding: 'utf8' },
  ).trim();
}

export function dbItemIdByBarcode(barcode: string): number {
  const raw = queryLocalDb(`SELECT id FROM myitems WHERE barcode='${barcode.replace(/'/g, "''")}' AND isdeleted=0 LIMIT 1`);
  const id = Number(raw);
  expect(id).toBeGreaterThan(0);
  return id;
}

export function dbUnitFlags(itemId: number): Array<{ unit_id: number; def_sale: number; def_stock: number; def_buy: number; u_val: string; price1: string; cost_price: string }> {
  const raw = queryLocalDb(
    `SELECT unit_id, def_sale, def_stock, def_buy, u_val, price1, cost_price FROM item_units WHERE item_id=${itemId} ORDER BY id`,
  );
  if (!raw) {
    return [];
  }
  return raw.split('\n').map((line) => {
    const [unit_id, def_sale, def_stock, def_buy, u_val, price1, cost_price] = line.split('\t');
    return {
      unit_id: Number(unit_id),
      def_sale: Number(def_sale),
      def_stock: Number(def_stock),
      def_buy: Number(def_buy),
      u_val,
      price1,
      cost_price,
    };
  });
}

export async function assertEditProfileState(
  page: Page,
  expected: {
    type: ItemEditorType;
    sellPrice?: string;
    cost?: string;
    sellActive?: boolean;
  },
): Promise<void> {
  await expect(page.locator('#item_type')).toHaveValue(expected.type);
  if (expected.sellPrice !== undefined) {
    await expect(page.locator('#sell_price1')).toHaveValue(expected.sellPrice);
  }
  if (expected.cost !== undefined) {
    await expect(page.locator('#direct_cost_price')).toHaveValue(expected.cost);
  }
  if (expected.sellActive !== undefined) {
    await expect(page.locator('#sell_active')).toHaveValue(expected.sellActive ? '1' : '0');
  }
}
