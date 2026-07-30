'use strict';

const fs = require('fs');
const vm = require('vm');
const path = require('path');

function assert(condition, message) {
    if (!condition) {
        throw new Error(message);
    }
}

const root = path.resolve(__dirname, '../..');
const fields = {
    age: { value: '2' },
    selected_table_id: { value: '1' },
    table_id: { value: '1' },
    edit_id: { value: '10' },
    selected_order_id: { value: '10' },
    mutation_version: { value: '3' },
    paid_cash: { value: '0.10' },
    paid_bank: { value: '0.20' },
    paid: { value: '999.99' },
    headtotal: { value: '10.30' },
    headdisc: { value: '0.00' },
    headnet: { value: '10.30' },
    pro_date: { value: '2026-07-27' },
    info: { value: 'mixed proof' }
};
const arrays = {
    itmname: [{ value: '7' }],
    itmqty: [{ value: '1.250000' }],
    itmprice: [{ value: '8.240000' }],
    itmdisc: [{ value: '0.000000' }],
    itmnote: [{ value: '' }],
    itmpreparation: [{ value: '[]' }]
};
const form = {
    querySelector(selector) {
        if (selector === 'input[name="age"]:checked') {
            return fields.age;
        }
        const match = selector.match(/^\[name="([^"]+)"\]$/);
        return match ? (fields[match[1]] || null) : null;
    },
    querySelectorAll(selector) {
        const matches = [...selector.matchAll(/\[name="([^"]+?)(?:\[\])?"\]/g)];
        for (const match of matches) {
            const key = match[1].replace(/\[\]$/, '');
            if (arrays[key]) {
                return arrays[key];
            }
        }
        return [];
    }
};
const context = {
    window: {
        crypto: { randomUUID: () => 'browser-money-contract' },
        location: { search: '', pathname: '/pos_barcode.php' },
        history: { replaceState() {} }
    },
    document: {
        querySelector() { return null; },
        getElementById(id) { return id === 'posForm' ? form : null; },
        createElement() { return {}; }
    },
    console,
    URLSearchParams,
    fetch() { throw new Error('network must not be used by this contract'); },
    setTimeout,
    clearTimeout
};
context.window.window = context.window;
context.window.document = context.document;
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(root, 'js/pos_order_api.js'), 'utf8'), context);

const api = context.window.POSOrderApi;
context.window.POSMAIN_CAPABILITIES = {};
context.window.POSMAIN_LIMITS = {
    'pos.discount.apply': { is_unlimited: false, limit_value: '12.537312' }
};
vm.runInContext(fs.readFileSync(path.join(root, 'js/posmain_capabilities.js'), 'utf8'), context);
assert(api.decimalString('10.5', 2, '0') === '10.50', 'money must normalize as a decimal string');
assert(api.addDecimalStrings('0.10', '0.20', 2) === '0.30', 'money addition must not use binary floats');
assert(api.compareDecimalStrings('10.00', '9.99', 2) === 1, 'money comparison must be exact');
assert(api.subtractDecimalStrings('10.00', '10.01', 2) === '-0.01', 'money subtraction must preserve an exact negative shortage');
assert(api.prorateMoneyByQuantity('10.00', '1.000000', '3.000000') === '3.33', 'partial-quantity value must use deterministic half-up cent rounding');
assert(api.lineTotalFromQuantityAndUnitPrice('1.250000', '8.240000') === '10.30', 'browser line total must match the server quantity and unit-price scales');
assert(api.allocateProportionalMoney('5.00', '30.00', '50.00') === '3.00', 'header discount allocation must match the server money rule');
assert(api.quantityFromIntegerRatio('1250', '1000') === '1.250000', 'scale barcode quantity must not use binary division');
assert(api.moneyFromPercentage('10.05', '12.500000') === '1.26', 'percentage discount must use deterministic half-up cent rounding');
assert(api.percentageFromMoney('1.26', '10.05') === '12.537313', 'discount authorization percentage must be computed exactly');
assert(
    context.window.POSMAIN.checkAmountWithinLimit('pos.discount.apply', '12.537313') === false,
    'permission limits must compare exact decimals without float tolerance'
);

const payload = api.buildOrderPayload(form, 'cash');
assert(payload.paid === '0.30', 'mixed tender total must be exact');
assert(payload.payment_method === 'mixed', 'mixed tender must not collapse to bank');
assert(payload.tenders.length === 2, 'cash and bank must remain separate tenders');
assert(payload.tenders[0].payment_method === 'bank' && payload.tenders[0].amount === '0.20', 'bank tender must be explicit and first');
assert(payload.tenders[1].payment_method === 'cash' && payload.tenders[1].amount === '0.10', 'cash tender must be explicit');
assert(payload.items[0].qty === '1.250000', 'quantity must remain a decimal string');
assert(payload.items[0].price === '8.240000', 'unit price must remain a decimal string');
assert(typeof payload.net === 'string' && typeof payload.discount === 'string', 'financial JSON fields must be strings');

let excessScaleRejected = false;
try {
    api.decimalString('1.001', 2, '0');
} catch (error) {
    excessScaleRejected = error.message === 'DECIMAL_SCALE_EXCEEDED';
}
assert(excessScaleRejected, 'browser boundary must reject excess money scale');

context.$ = function () {
    return {
        ready() {},
        on() { return this; }
    };
};
context.window.$ = context.$;
context.window.jQuery = context.$;
vm.runInContext(fs.readFileSync(path.join(root, 'js/pos_tables.js'), 'utf8'), context);
assert(
    vm.runInContext("posTableLineTotal('3.000000', '0.100000')", context) === '0.30',
    'table workspace line totals must use the shared exact browser kernel'
);
const tableItems = vm.runInContext(
    "posTableSerializableItems([{id: 7, qty: '1.250000', price: '8.240000', discount: '0'}])",
    context
);
assert(tableItems[0].qty === '1.250000', 'table workspace quantity must preserve the certified 6dp scale');
assert(tableItems[0].price === '8.240000', 'table workspace unit price must remain an exact string');

console.log('commercial-v1-browser-money-contract-ok');
