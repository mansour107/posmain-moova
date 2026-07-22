#!/usr/bin/env node

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.resolve(__dirname, '../../js/pos_order_api.js'), 'utf8');

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

function jqueryStub() {
  return {
    length: 0,
    prop() { return this; },
    html() { return this; },
    text() { return this; },
    modal() { return this; },
    val() { return ''; },
  };
}

function createForm() {
  const values = {
    age: '1',
    headtotal: '100',
    headnet: '100',
    headdisc: '0',
    paid: '100',
    paid_cash: '100',
    paid_bank: '0',
  };
  const lineFields = {
    '[name="itmname[]"], [name="itmname"]': [{ value: '11' }],
    '[name="itmqty[]"], [name="itmqty"]': [{ value: '1' }],
    '[name="itmprice[]"], [name="itmprice"]': [{ value: '100' }],
    '[name="itmdisc[]"], [name="itmdisc"]': [{ value: '0' }],
    '[name="itmnote[]"], [name="itmnote"]': [{ value: '' }],
    '[name="itmpreparation[]"], [name="itmpreparation"]': [{ value: '' }],
  };
  const dynamicFields = Object.create(null);

  return {
    querySelector(selector) {
      if (selector === 'input[name="age"]:checked') {
        return { value: values.age };
      }
      if (selector === 'input[name="idempotency_key"]') {
        return dynamicFields.idempotency_key || null;
      }
      const match = selector.match(/^\[name="([^"]+)"\]$/);
      if (!match) {
        return null;
      }
      if (dynamicFields[match[1]]) {
        return dynamicFields[match[1]];
      }
      return Object.prototype.hasOwnProperty.call(values, match[1]) ? { value: values[match[1]] } : null;
    },
    querySelectorAll(selector) {
      return lineFields[selector] || [];
    },
    appendChild(field) {
      if (field && field.name) {
        dynamicFields[field.name] = field;
      }
    },
  };
}

function jsonResponse(status, body) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  };
}

function createRuntime(fetchImpl) {
  const form = createForm();
  let key = '';
  let sequence = 0;
  let saving = false;
  const draft = {
    canSave: (action) => (action === 'save' || action === 'print_receipt' ? !saving : true),
    rotateIdempotencyKey() {
      sequence += 1;
      key = `intent:${sequence}`;
      return key;
    },
    ensureFormIdempotencyKey() {
      return key || this.rotateIdempotencyKey();
    },
    clearIdempotencyKey() { key = ''; },
    markSaving() { saving = true; },
    markSaveFailed() { saving = false; },
    markSaved() { saving = false; },
  };
  const document = {
    querySelector: () => null,
    getElementById: (id) => (id === 'posForm' ? form : null),
    createElement: () => ({ type: '', name: '', value: '', dataset: {} }),
  };
  const window = {
    document,
    jQuery: jqueryStub,
    POSOrderDraft: draft,
    crypto: { randomUUID: () => 'runtime-test-uuid' },
    setTimeout: (callback) => callback(),
    Swal: null,
    alert() {},
    fetch: fetchImpl,
  };
  window.window = window;

  const context = {
    window,
    document,
    fetch: fetchImpl,
    alert() {},
    console,
    Promise,
    WeakMap,
    Object,
    String,
    Date,
    Math,
    JSON,
    parseInt,
    parseFloat,
    encodeURIComponent,
  };
  vm.createContext(context);
  vm.runInContext(source, context);
  return { api: window.POSOrderApi, form, window };
}

async function rapidSubmitReusesInFlightPromise() {
  let calls = 0;
  let resolveFetch;
  const runtime = createRuntime(() => {
    calls += 1;
    return new Promise((resolve) => { resolveFetch = resolve; });
  });

  const first = runtime.api.submitFromForm(runtime.form, 'cash');
  const second = runtime.api.submitFromForm(runtime.form, 'cash');
  const competingSave = runtime.api.submitFromForm(runtime.form, 'save');
  assert(first === second, 'rapid duplicate submit must return the existing in-flight promise');
  assert(first === competingSave, 'a competing form mutation must share the existing in-flight promise');
  assert(calls === 1, 'rapid or competing submit must issue one HTTP request');

  resolveFetch(jsonResponse(200, { success: true }));
  const result = await first;
  assert(result.success === true, 'the shared in-flight request should resolve successfully');
}

async function processingRetryRetainsExactIntent() {
  const requests = [];
  const responses = [
    jsonResponse(423, { success: false, code: 'IDEMPOTENCY_PROCESSING' }),
    jsonResponse(423, { success: false, code: 'IDEMPOTENCY_PROCESSING' }),
    jsonResponse(200, { success: true }),
  ];
  const runtime = createRuntime((url, options) => {
    requests.push(options.body);
    return Promise.resolve(responses.shift());
  });

  const processing = await runtime.api.submitFromForm(runtime.form, 'cash');
  assert(processing.success === false, 'processing response should remain retryable after the short retry');
  assert(processing.reuseIdempotencyKey === true, 'processing response must retain its intent');

  const completed = await runtime.api.submitFromForm(runtime.form, 'save');
  assert(completed.success === true, 'manual retry of the retained intent should complete');
  assert(requests.length === 3, 'processing flow should perform two automatic attempts and one manual retry');
  assert(requests[0] === requests[1] && requests[1] === requests[2], 'every retry must reuse the exact serialized payload and idempotency key');
}

async function cancelledNetworkPromptStillRetainsIntent() {
  const requests = [];
  let fail = true;
  const runtime = createRuntime((url, options) => {
    requests.push(options.body);
    if (fail) {
      fail = false;
      return Promise.reject(new Error('connection lost'));
    }
    return Promise.resolve(jsonResponse(200, { success: true }));
  });

  const cancelled = await runtime.api.submitFromForm(runtime.form, 'cash');
  assert(cancelled.cancelled === true, 'closing the network retry prompt should settle the in-flight promise');
  assert(cancelled.reuseIdempotencyKey === true, 'unknown network outcome must remain retryable');

  const completed = await runtime.api.submitFromForm(runtime.form, 'cash');
  assert(completed.success === true, 'later submit should reconcile the cancelled network prompt intent');
  assert(requests.length === 2, 'network cancellation followed by retry should issue two requests');
  assert(requests[0] === requests[1], 'network cancellation retry must reuse the exact payload and key');
}

async function managerApprovalRetryStaysInsideOriginalIntent() {
  const requests = [];
  const responses = [
    jsonResponse(403, { success: false, code: 'MANAGER_APPROVAL_REQUIRED', permission_key: 'pos.discount' }),
    jsonResponse(200, { success: true }),
  ];
  const runtime = createRuntime((url, options) => {
    requests.push(JSON.parse(options.body));
    return Promise.resolve(responses.shift());
  });
  runtime.window.POSMAIN = {
    requestManagerOverride: () => Promise.resolve({ approval_id: 77 }),
  };

  const result = await runtime.api.submitFromForm(runtime.form, 'save');
  assert(result.success === true, 'manager-approved internal retry should bypass the saving-state guard');
  assert(requests.length === 2, 'manager approval should issue exactly one authorized retry');
  assert(requests[0].idempotency_key === requests[1].idempotency_key, 'manager retry must retain the original intent key');
  assert(requests[1].manager_approval_id === '77', 'manager retry must add the approval to the new request payload');
}

Promise.resolve()
  .then(rapidSubmitReusesInFlightPromise)
  .then(processingRetryRetainsExactIntent)
  .then(cancelledNetworkPromptStillRetainsIntent)
  .then(managerApprovalRetryStaysInsideOriginalIntent)
  .then(() => console.log('pos-order-api-idempotency-runtime-ok'))
  .catch((error) => {
    console.error(error.stack || error.message || error);
    process.exit(1);
  });
