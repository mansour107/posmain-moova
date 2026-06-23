#!/usr/bin/env node

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const widgetPath = path.resolve(__dirname, '../../assets/moova-pos-widget/pos-widget.js');
const source = fs.readFileSync(widgetPath, 'utf8');

function extractFunction(sourceText, name) {
  const start = sourceText.indexOf(`function ${name}(`);
  if (start < 0) {
    throw new Error(`missing function ${name}`);
  }
  let depth = 0;
  let started = false;
  for (let i = start; i < sourceText.length; i += 1) {
    const ch = sourceText[i];
    if (ch === '{') {
      depth += 1;
      started = true;
    } else if (ch === '}') {
      depth -= 1;
      if (started && depth === 0) {
        return sourceText.slice(start, i + 1);
      }
    }
  }
  throw new Error(`could not extract function ${name}`);
}

const context = {
  state: { transportError: null },
  t(key) {
    return key === 'moovaUnreachable' ? 'Moova is unreachable.' : key;
  },
};

const script = `
function asText(value) { return value == null ? '' : String(value).trim(); }
function messageForApiError(code) { return code === 'MOOVA_UNREACHABLE' ? t('moovaUnreachable') : null; }
${extractFunction(source, 'extractBridgeTransportError')}
${extractFunction(source, 'applyBridgeHealth')}
`;

vm.createContext(context);
vm.runInContext(script, context);

context.applyBridgeHealth({ drafts: [], commands: [], remoteReachable: true });
if (context.state.transportError !== null) {
  console.error('healthy bridge should clear transportError');
  process.exit(1);
}

context.applyBridgeHealth({
  drafts: [],
  commands: [],
  fallback: true,
  remoteReachable: false,
  warning: {
    code: 'MOOVA_UNREACHABLE',
    message: 'Moova is unreachable. Check the Moova service connection and try again.',
  },
});
if (!context.state.transportError || context.state.transportError.code !== 'MOOVA_UNREACHABLE') {
  console.error('degraded bridge should set transportError', context.state.transportError);
  process.exit(1);
}

console.log('moova-widget-bridge-health-runtime-ok');
console.log(JSON.stringify({ degradedTransportError: context.state.transportError }, null, 2));
