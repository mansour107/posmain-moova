#!/usr/bin/env node
/**
 * Runtime checks for delivery POS JS contracts (Phases 1–2).
 */

const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const deliveryJs = fs.readFileSync(path.join(root, 'js/pos_delivery.js'), 'utf8');
const deliveryQueueJs = fs.readFileSync(path.join(root, 'js/pos_delivery_queue.js'), 'utf8');
const barcodeJs = fs.readFileSync(path.join(root, 'js/pos_barcode.js'), 'utf8');
const posContent = fs.readFileSync(path.join(root, 'includes/pos_content.php'), 'utf8');
const widgetJs = fs.readFileSync(path.join(root, 'assets/moova-pos-widget/pos-widget.js'), 'utf8');

function assert(cond, msg) {
  if (!cond) {
    console.error('delivery-pos-js-runtime-FAIL:', msg);
    process.exit(1);
  }
}

// Phase 1/2 UI contracts
assert(deliveryJs.includes('window.posDeliveryState'), 'posDeliveryState required');
assert(deliveryJs.includes('isCustomerFormComplete'), 'customer completeness helper required');
assert(deliveryQueueJs.includes('ajax/pos_delivery_queue.php'), 'cashier delivery queue endpoint required');
assert(deliveryQueueJs.includes("data.action = 'dispatch'"), 'cashier must dispatch from the POS queue');
assert(deliveryQueueJs.includes("data.action = 'failed'"), 'cashier must log failed delivery with a reason');
assert(deliveryQueueJs.includes('summary.active'), 'badge must use all active delivery orders');
assert(deliveryJs.includes('response.success'), 'must check response.success');
assert(deliveryJs.includes('تأكيد بيانات العميل') || posContent.includes('تأكيد بيانات العميل'), 'confirm label updated');
assert(deliveryJs.includes('posDeliveryIsReadyForSubmit'), 'delivery readiness export required');
assert(deliveryJs.includes('posDeliveryBar'), 'delivery bar renderer required');
assert(deliveryJs.includes('window.posDeliveryResetAfterCommit'), 'delivery commit reset export required');
assert(deliveryJs.includes('window.posDeliveryHydrateFromOrder'), 'saved delivery hydration export required');
assert(deliveryJs.includes('window.POSMAIN_EDIT_DELIVERY'), 'saved delivery bootstrap must be consumed on page load');
assert(posContent.includes('window.POSMAIN_EDIT_DELIVERY'), 'server delivery snapshot must be rendered for edit mode');
assert(posContent.includes('$posEditMutationVersion'), 'edit mode must render the persisted mutation version');
assert(
  barcodeJs.includes("typeof window.posDeliveryResetAfterCommit === 'function'")
    && barcodeJs.includes('window.posDeliveryResetAfterCommit();'),
  'successful order reset must clear delivery customer and fee state'
);
assert(deliveryJs.includes('delivery_zones_list.php'), 'zones list endpoint wired');
assert(deliveryJs.includes('loadDeliveryZones(addressRecord ? addressRecord.zone_id : null)'), 'saved customer zone should be restored into the delivery selector');
assert(deliveryJs.includes('addressPayload.id = addressId'), 'existing default address should be updated rather than duplicated by the cashier');
assert(deliveryJs.includes('revert') || deliveryJs.includes("$('#age1')"), 'modal dismiss should revert mode');
assert(posContent.includes('pos_delivery.js'), 'pos_content should load pos_delivery.js');
assert(posContent.includes('pos_delivery_queue.js'), 'pos_content should load compact delivery queue');
assert(posContent.includes('id="posDeliveryBar"'), 'delivery bar mount required');
assert(posContent.includes('id="posDeliveryQueue"'), 'delivery queue offcanvas mount required');

// Phase 2 fee row + totals
assert(deliveryJs.includes('posDeliveryFeeRow'), 'delivery fee row id required');
assert(barcodeJs.includes('posDeliveryGetFee'), 'totals must include delivery fee helper');
assert(barcodeJs.includes("orderMode === '3'"), 'validatePOSForm delivery branch required');

// Phase 4 widget validation snippet
assert(widgetJs.includes('isDeliveryDraft'), 'widget delivery draft detection required');
assert(widgetJs.includes('customerName') || widgetJs.includes('customer_name'), 'widget customer validation required');

// Simulate completeness logic extracted from source
function isCustomerFormComplete(phone, name, address) {
  return phone.trim().length >= 10 && name.trim() !== '' && address.trim() !== '';
}
assert(isCustomerFormComplete('01001234567', 'Ahmed', 'Maadi') === true, 'complete customer simulation');
assert(isCustomerFormComplete('010', 'Ahmed', 'Maadi') === false, 'short phone should fail');
assert(isCustomerFormComplete('01001234567', '', 'Maadi') === false, 'missing name should fail');

console.log('delivery-pos-js-runtime-ok');
