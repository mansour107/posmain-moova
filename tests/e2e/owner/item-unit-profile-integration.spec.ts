import { test, expect, type Page } from '@playwright/test';
import { loginAs, unlockPos, assertNoFatalText } from '../helpers/auth';
import {
  assertEditProfileState,
  dbItemIdByBarcode,
  dbUnitFlags,
  fillCreateItemForm,
  ITEM_EDITOR_UNITS,
  openAddItemEditor,
  openItemEditFromCatalog,
  queryLocalDb,
  saveItemClose,
  selectItemType,
  selectItemUnit,
  setPurchaseSectionActive,
  uniqueItemLabel,
  type CreateItemProfile,
} from '../helpers/item-editor';
import { saveTakeawayOrder } from '../helpers/pos';

async function createItemViaProfile(page: Page, profile: CreateItemProfile): Promise<number> {
  await fillCreateItemForm(page, profile);
  await saveItemClose(page);
  return dbItemIdByBarcode(profile.barcode);
}

test.describe.serial('owner: item unit profile full browser integration', () => {
  const fixtures: Record<string, { name: string; barcode: string; itemId?: number; sellPrice?: string }> = {};

  test.beforeEach(async ({ page }) => {
    await loginAs(page, 'admin');
  });

  test('sellable default — قطعة only, sell price required and persisted', async ({ page }) => {
    const { name, barcode } = uniqueItemLabel('SellableDefault');
    fixtures.sellableDefault = { name, barcode, sellPrice: '15.5' };

    const itemId = await createItemViaProfile(page, {
      name,
      barcode,
      type: 'sellable',
      sell: { unitId: ITEM_EDITOR_UNITS.piece, price1: '15.5' },
    });

    fixtures.sellableDefault.itemId = itemId;
    const flags = dbUnitFlags(itemId);
    expect(flags.length).toBe(1);
    expect(flags[0].def_sale).toBe(1);
    expect(flags[0].def_stock).toBe(1);
    expect(Number(flags[0].price1)).toBeCloseTo(15.5, 2);

    const headerPrice = queryLocalDb(`SELECT price1 FROM myitems WHERE id=${itemId}`);
    expect(Number(headerPrice)).toBeCloseTo(15.5, 2);

    await openItemEditFromCatalog(page, barcode);
    await assertEditProfileState(page, { type: 'sellable', sellPrice: '15.5' });
  });

  test('sellable purchase pack — قطعة sell, كرتونة buy x12 with cost', async ({ page }) => {
    const { name, barcode } = uniqueItemLabel('SellablePack');
    fixtures.sellablePack = { name, barcode, sellPrice: '8' };

    const itemId = await createItemViaProfile(page, {
      name,
      barcode,
      type: 'sellable',
      sell: { unitId: ITEM_EDITOR_UNITS.piece, price1: '8' },
      purchaseActive: true,
      purchase: {
        storageUnitId: ITEM_EDITOR_UNITS.piece,
        purchaseUnitId: ITEM_EDITOR_UNITS.carton,
        purchaseStorageFactor: '12',
        cost: '96',
        purchaseBarcode: `P${barcode.slice(0, 8)}`,
      },
    });

    fixtures.sellablePack.itemId = itemId;
    const flags = dbUnitFlags(itemId);
    expect(flags.length).toBe(2);
    const buy = flags.find((row) => row.def_buy === 1);
    const stock = flags.find((row) => row.def_stock === 1);
    expect(stock).toBeTruthy();
    expect(buy).toBeTruthy();
    expect(Number(buy!.u_val)).toBeCloseTo(12, 2);
    expect(Number(buy!.cost_price)).toBeCloseTo(96, 2);

    await openItemEditFromCatalog(page, barcode);
    await assertEditProfileState(page, { type: 'sellable', sellPrice: '8', purchaseCost: '96', purchaseActive: true });
    await expect(page.locator('#purchase_unit_id')).toHaveValue(ITEM_EDITOR_UNITS.carton);
  });

  test('sellable sell≠storage conversion when purchase section active', async ({ page }) => {
    const { name, barcode } = uniqueItemLabel('SellStorageConv');
    fixtures.sellableConv = { name, barcode, sellPrice: '22' };

    const itemId = await createItemViaProfile(page, {
      name,
      barcode,
      type: 'sellable',
      sell: { unitId: ITEM_EDITOR_UNITS.piece, price1: '22' },
      purchaseActive: true,
      purchase: {
        storageUnitId: ITEM_EDITOR_UNITS.kg,
        purchaseUnitId: ITEM_EDITOR_UNITS.kg,
        purchaseStorageFactor: '1',
        sellStorageFactor: '4',
        cost: '40',
      },
    });

    fixtures.sellableConv.itemId = itemId;
    const flags = dbUnitFlags(itemId);
    expect(flags.length).toBeGreaterThanOrEqual(2);
    const sell = flags.find((row) => row.def_sale === 1);
    const stock = flags.find((row) => row.def_stock === 1);
    expect(sell?.unit_id).toBe(Number(ITEM_EDITOR_UNITS.piece));
    expect(stock?.unit_id).toBe(Number(ITEM_EDITOR_UNITS.kg));
    expect(Number(sell?.u_val)).toBeCloseTo(4, 3);
  });

  test('ingredient — storage required, sell off, purchase on', async ({ page }) => {
    const { name, barcode } = uniqueItemLabel('IngredientPurchase');
    fixtures.ingredient = { name, barcode };

    const itemId = await createItemViaProfile(page, {
      name,
      barcode,
      type: 'ingredient',
      sellActive: false,
      purchaseActive: true,
      purchase: {
        storageUnitId: ITEM_EDITOR_UNITS.kg,
        purchaseUnitId: ITEM_EDITOR_UNITS.carton,
        purchaseStorageFactor: '10',
        cost: '55',
      },
    });

    fixtures.ingredient.itemId = itemId;
    const flags = dbUnitFlags(itemId);
    const stock = flags.find((row) => row.def_stock === 1);
    const buy = flags.find((row) => row.def_buy === 1);
    expect(stock?.unit_id).toBe(Number(ITEM_EDITOR_UNITS.kg));
    expect(buy?.unit_id).toBe(Number(ITEM_EDITOR_UNITS.carton));
    expect(flags.every((row) => row.def_sale === 0)).toBeTruthy();

    const preferred = queryLocalDb(`SELECT preferred_unit_id FROM myitems WHERE id=${itemId}`);
    expect(Number(preferred)).toBe(Number(ITEM_EDITOR_UNITS.kg));

    await openItemEditFromCatalog(page, barcode);
    await assertEditProfileState(page, { type: 'ingredient', purchaseActive: true, purchaseCost: '55', sellActive: false });
  });

  test('ingredient with sell section enabled', async ({ page }) => {
    const { name, barcode } = uniqueItemLabel('IngredientSell');
    const itemId = await createItemViaProfile(page, {
      name,
      barcode,
      type: 'ingredient',
      sellActive: true,
      sell: { unitId: ITEM_EDITOR_UNITS.piece, price1: '3.5' },
      purchase: { storageUnitId: ITEM_EDITOR_UNITS.kg },
    });

    const flags = dbUnitFlags(itemId);
    const sell = flags.find((row) => row.def_sale === 1);
    expect(sell).toBeTruthy();
    expect(Number(sell!.price1)).toBeCloseTo(3.5, 2);

    await openItemEditFromCatalog(page, barcode);
    await assertEditProfileState(page, { type: 'ingredient', sellActive: true, sellPrice: '3.5' });
  });

  test('packaging — storage only default path', async ({ page }) => {
    const { name, barcode } = uniqueItemLabel('Packaging');
    const itemId = await createItemViaProfile(page, {
      name,
      barcode,
      type: 'packaging',
      purchase: { storageUnitId: ITEM_EDITOR_UNITS.piece },
    });

    const flags = dbUnitFlags(itemId);
    expect(flags.length).toBe(1);
    expect(flags[0].def_stock).toBe(1);

    await openItemEditFromCatalog(page, barcode);
    await assertEditProfileState(page, { type: 'packaging', purchaseActive: false, sellActive: false });
  });

  test('service — sell only, no purchase panel', async ({ page }) => {
    const { name, barcode } = uniqueItemLabel('Service');
    fixtures.service = { name, barcode, sellPrice: '12' };

    const itemId = await createItemViaProfile(page, {
      name,
      barcode,
      type: 'service',
      sell: { unitId: ITEM_EDITOR_UNITS.piece, price1: '12' },
    });

    fixtures.service.itemId = itemId;
    const trackStock = queryLocalDb(`SELECT track_stock FROM myitems WHERE id=${itemId}`);
    expect(Number(trackStock)).toBe(0);

    await openItemEditFromCatalog(page, barcode);
    await expect(page.locator('#item-purchase-section')).toHaveClass(/d-none/);
    await assertEditProfileState(page, { type: 'service', sellPrice: '12' });
  });

  test('recipe editor picks ingredient stock unit on lookup', async ({ page }) => {
    test.skip(!fixtures.ingredient?.name || !fixtures.ingredient?.itemId, 'ingredient fixture missing');

    const draftRecipeId = Number(
      queryLocalDb(`SELECT id FROM recipe_headers WHERE status='draft' ORDER BY id DESC LIMIT 1`),
    );
    expect(draftRecipeId).toBeGreaterThan(0);

    await page.goto(`/recipe_manage.php?recipe_id=${draftRecipeId}`);
    assertNoFatalText(await page.content());

    await expect(page.locator('#recipe-details')).toBeVisible();
    const lookup = page.locator('form[data-recipe-save-form="add-component"] .recipe-lookup-input').first();
    await expect(lookup, 'draft recipe must expose add-component lookup').toBeVisible({ timeout: 15_000 });

    const searchTerm = fixtures.ingredient!.barcode.slice(0, 12);
    const lookupResponse = page.waitForResponse(
      (resp) =>
        resp.url().includes('ajax/recipe_editor_lookup.php')
        && resp.url().includes('type=components')
        && resp.status() === 200,
      { timeout: 15_000 },
    );
    await lookup.fill(searchTerm);
    const response = await lookupResponse;
    const payload = await response.json();
    expect(Array.isArray(payload.items)).toBeTruthy();

    const match = (payload.items as Array<{ id: number; stock_unit_id?: number }>).find(
      (item) => item.id === fixtures.ingredient!.itemId,
    );
    expect(match, 'lookup must return the ingredient fixture').toBeTruthy();
    expect(Number(match!.stock_unit_id)).toBe(Number(ITEM_EDITOR_UNITS.kg));

    const resultButton = page
      .locator('form[data-recipe-save-form="add-component"] .recipe-lookup-results button')
      .filter({ hasText: fixtures.ingredient!.barcode })
      .first();
    await expect(resultButton).toBeVisible({ timeout: 5_000 });
    await resultButton.click();

    const unitSelect = page.locator('form[data-recipe-save-form="add-component"] select[name="unit_id"]');
    await expect(unitSelect).toHaveValue(ITEM_EDITOR_UNITS.kg);
  });

  test('POS barcode scan adds sellable item with correct price', async ({ page }) => {
    test.skip(!fixtures.sellableDefault?.barcode, 'sellable fixture missing');

    await unlockPos(page, 'admin');
    const barcode = fixtures.sellableDefault!.barcode;
    const expectedPrice = Number(fixtures.sellableDefault!.sellPrice ?? '15.5');

    const searchResponse = page.waitForResponse((response) =>
      response.url().includes('ajax/search_item.php') && response.request().method() === 'POST',
    );
    await page.locator('#posUnifiedSearch').fill(barcode);
    await page.locator('#posUnifiedSearch').press('Enter');
    const response = await searchResponse;
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.success).toBeTruthy();
    expect(Number(body.item.price)).toBeCloseTo(expectedPrice, 2);

    await expect(page.locator('#itemData .item-card-order')).toHaveCount(1, { timeout: 10_000 });

    const { orderId } = await saveTakeawayOrder(page);
    expect(orderId).toBeGreaterThan(0);
  });

  test('conversion factor exposed to POS search for pack sellable', async ({ page }) => {
    test.skip(!fixtures.sellableConv?.barcode, 'conversion fixture missing');

    await unlockPos(page, 'admin');
    const searchResponse = page.waitForResponse((response) =>
      response.url().includes('ajax/search_item.php'),
    );
    await page.locator('#posUnifiedSearch').fill(fixtures.sellableConv!.barcode);
    await page.locator('#posUnifiedSearch').press('Enter');
    const response = await searchResponse;
    const body = await response.json();
    expect(body.success).toBeTruthy();
    expect(Number(body.item.u_val)).toBeGreaterThan(0);
  });

  test('client validation blocks purchase section without cost', async ({ page }) => {
    const label = uniqueItemLabel('InvalidPurchase');
    await fillCreateItemForm(page, {
      name: label.name,
      barcode: label.barcode,
      type: 'sellable',
      sell: { price1: '5' },
      purchaseActive: true,
      purchase: {
        storageUnitId: ITEM_EDITOR_UNITS.piece,
        purchaseUnitId: ITEM_EDITOR_UNITS.carton,
        purchaseStorageFactor: '12',
        cost: '0',
      },
    });

    page.once('dialog', async (dialog) => {
      expect(dialog.message()).toContain('تكلفة الشراء');
      await dialog.dismiss();
    });
    await page.getByRole('button', { name: /حفظ وإغلاق/ }).click();
    await expect(page).toHaveURL(/add_item\.php/);
  });

  test('swap conversion direction updates factor field', async ({ page }) => {
    await openAddItemEditor(page);
    await selectItemType(page, 'sellable');
    await page.fill('#iname', uniqueItemLabel('Swap').name);
    await page.fill('input[name="barcode"]', uniqueItemLabel('Swap').barcode);
    await page.fill('#sell_price1', '9');
    await setPurchaseSectionActive(page, true);
    await selectItemUnit(page, 'sell_unit_id', ITEM_EDITOR_UNITS.piece);
    await selectItemUnit(page, 'storage_unit_id', ITEM_EDITOR_UNITS.kg);
    await selectItemUnit(page, 'purchase_unit_id', ITEM_EDITOR_UNITS.kg);
    await page.fill('#purchase_cost', '10');
    await page.fill('#sell_storage_factor', '4');
    await expect(page.locator('#sell-storage-conversion')).toBeVisible();
    await page.locator('#sell-storage-conversion .item-unit-conversion__swap').click();
    await expect(page.locator('#sell_storage_factor')).toHaveValue('0.25');
  });
});
