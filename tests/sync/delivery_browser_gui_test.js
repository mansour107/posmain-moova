#!/usr/bin/env node
/**
 * Browser GUI smoke for delivery POS surfaces (Phase 2 + 6).
 * Requires: npx, running posmain-php on POSMAIN_TEST_HTTP_BASE (default http://127.0.0.1:8010)
 */

const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const base = process.env.POSMAIN_TEST_HTTP_BASE || 'http://127.0.0.1:8010';
const pwcli = path.join(process.env.CODEX_HOME || `${process.env.HOME}/.codex`, 'skills/playwright/scripts/playwright_cli.sh');

function runPw(args) {
  const result = spawnSync(pwcli, args, { encoding: 'utf8', timeout: 120000 });
  return {
    status: result.status,
    stdout: result.stdout || '',
    stderr: result.stderr || '',
  };
}

function assert(cond, msg) {
  if (!cond) {
    console.error('delivery-browser-gui-FAIL:', msg);
    process.exit(1);
  }
}

if (!fs.existsSync(pwcli)) {
  console.log('delivery-browser-gui-skipped-playwright-cli-missing');
  process.exit(0);
}

// Static asset checks (no auth)
const deliveryJsRes = spawnSync('curl', ['-fsS', `${base}/js/pos_delivery.js`], { encoding: 'utf8' });
assert(deliveryJsRes.status === 0, 'pos_delivery.js should be served');
assert(deliveryJsRes.stdout.includes('posDeliveryBar'), 'served pos_delivery.js should contain delivery bar logic');

const cssRes = spawnSync('curl', ['-fsS', `${base}/dist/css/pos_barcode.css`], { encoding: 'utf8' });
assert(cssRes.status === 0, 'pos_barcode.css should be served');
assert(cssRes.stdout.includes('pos-delivery-bar'), 'css should include delivery bar styles');

// Playwright: load login page and verify delivery board redirects to auth (expected)
const openLogin = runPw(['open', `${base}/index.php`]);
assert(openLogin.status === 0, 'should open login page: ' + openLogin.stderr);
const snapLogin = runPw(['snapshot']);
assert(snapLogin.status === 0, 'login snapshot failed');
assert(/login|دخول|username|password/i.test(snapLogin.stdout), 'login page should render auth form');

const openBoard = runPw(['open', `${base}/delivery_board.php`]);
assert(openBoard.status === 0, 'should open delivery board page');
const snapBoard = runPw(['snapshot']);
assert(snapBoard.status === 0, 'board snapshot failed');
// Unauthenticated users should not see board columns
const boardProtected = !/deliveryBoardColumns|لوحة توصيل/.test(snapBoard.stdout)
  || /login|دخول|index\.php/i.test(snapBoard.stdout);
assert(boardProtected, 'delivery board should require authentication');

runPw(['close']);

console.log('delivery-browser-gui-ok');
console.log(JSON.stringify({ base, checks: ['pos_delivery.js', 'pos_barcode.css', 'login_page', 'delivery_board_auth'] }, null, 2));
