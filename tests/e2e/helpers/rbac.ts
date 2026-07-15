import { expect, type APIRequestContext, type APIResponse, type Page } from '@playwright/test';
import type { PersonaRole } from './env';

/** Mirrors config/rbac_page_manifest.php — paths relative to site root */
export const RBAC_GUARDED_PAGES: Array<{
  path: string;
  permission: string | null;
  adminOr?: boolean;
}> = [
  { path: '/team.php', permission: 'users.manage', adminOr: true },
  { path: '/users.php', permission: 'users.manage', adminOr: true },
  { path: '/myroles.php', permission: 'roles.manage', adminOr: true },
  { path: '/add_role.php', permission: 'roles.manage', adminOr: true },
  { path: '/setting.php', permission: 'system.tools.run', adminOr: true },
  { path: '/myitems.php', permission: 'menu.edit' },
  { path: '/add_item.php', permission: 'menu.edit' },
  { path: '/inventory_dashboard.php', permission: 'inventory.edit' },
  { path: '/vouchers.php', permission: 'accounting.view' },
  { path: '/employees.php', permission: 'reports.view' },
];

export const RBAC_DENIED_PAGE_MATRIX: Array<{ role: PersonaRole; path: string; permission: string }> = [
  { role: 'cashier', path: '/team.php', permission: 'users.manage' },
  { role: 'cashier', path: '/users.php', permission: 'users.manage' },
  { role: 'cashier', path: '/myroles.php', permission: 'roles.manage' },
  { role: 'cashier', path: '/setting.php', permission: 'system.tools.run' },
  { role: 'cashier', path: '/add_item.php', permission: 'menu.edit' },
  { role: 'cashier', path: '/myitems.php', permission: 'menu.edit' },
  { role: 'cashier', path: '/vouchers.php', permission: 'accounting.view' },
  { role: 'cashier', path: '/employees.php', permission: 'reports.view' },
  { role: 'waiter', path: '/users.php', permission: 'users.manage' },
  { role: 'waiter', path: '/myitems.php', permission: 'menu.edit' },
  { role: 'manager', path: '/users.php', permission: 'users.manage' },
  { role: 'manager', path: '/setting.php', permission: 'system.tools.run' },
];

export const RBAC_ALLOWED_PAGE_MATRIX: Array<{ role: PersonaRole; path: string; permission: string; hint?: RegExp }> = [
  { role: 'admin', path: '/team.php', permission: 'users.manage', hint: /الفريق|الموظفون/i },
  { role: 'admin', path: '/users.php', permission: 'users.manage', hint: /الفريق|الموظفون/i },
  { role: 'admin', path: '/myroles.php', permission: 'roles.manage', hint: /الفريق|الأدوار/i },
  { role: 'manager', path: '/myitems.php', permission: 'menu.edit' },
  { role: 'cashier', path: '/change_password.php', permission: 'pos.open' },
];

export const RBAC_CRITICAL_POST_HANDLERS: Array<{
  path: string;
  label: string;
  permission: string;
  body?: Record<string, string>;
}> = [
  { path: '/do/doadd_role.php', label: 'create role', permission: 'roles.manage', body: { rollname: 'rbac-e2e-blocked', info: 'blocked' } },
  { path: '/do/doadd_user.php', label: 'create user', permission: 'users.manage', body: { uname: 'rbac_blocked_user', name: 'Blocked', password: 'x', userrole: '2' } },
  { path: '/do/doadd_item.php', label: 'create item', permission: 'menu.edit', body: { iname: 'rbac-blocked-item' } },
  { path: '/do/doedit_settings.php', label: 'edit settings', permission: 'system.tools.run', body: { company_name: 'blocked' } },
];

const TEAM_HUB_LEGACY_REDIRECTS: Record<string, RegExp> = {
  '/users.php': /\/team\.php(\?|$)/,
  '/myroles.php': /\/team\.php(\?|$)/,
  '/add_role.php': /\/team\.php(\?|$)/,
  '/add_user.php': /\/team\.php(\?|$)/,
  '/edit_user.php': /\/team\.php(\?|$)/,
  '/edit_role.php': /\/team\.php(\?|$)/,
};

function normalizePagePath(path: string): string {
  const pathname = path.split('?')[0].split('#')[0];
  return pathname.startsWith('/') ? pathname : `/${pathname}`;
}

export function isAccessBlocked(
  response: APIResponse | null,
  body: string,
  url: string,
  requestedPath?: string,
): boolean {
  const status = response?.status() ?? 0;
  if (status === 401 || status === 403 || status === 302) {
    return true;
  }
  if (/PERMISSION_DENIED|ليس لديك صلاحية|AUTH_REQUIRED|POS_AUTH_REQUIRED/i.test(body)) {
    return true;
  }
  if (/index\.php|forbidden\.php/.test(url)) {
    return true;
  }
  if (requestedPath) {
    const requested = normalizePagePath(requestedPath);
    const finalPath = normalizePagePath(new URL(url).pathname);
    const legacyRedirect = TEAM_HUB_LEGACY_REDIRECTS[requested];
    if (legacyRedirect && legacyRedirect.test(url)) {
      return false;
    }
    if (finalPath !== requested) {
      return true;
    }
  }
  return false;
}

export function isHandlerRejected(response: APIResponse): boolean {
  const status = response.status();
  if (status === 401 || status === 403 || status === 302) {
    return true;
  }
  return response.url().includes('index.php');
}

export function assertNoApplicationError(body: string): void {
  expect(body).not.toMatch(/fatal error|parse error|uncaught exception|SQL syntax|mysqli_/i);
  expect(body).not.toMatch(/there is a big mistake/i);
}

export async function fetchCapabilities(request: APIRequestContext): Promise<Record<string, boolean>> {
  const response = await request.get('/ajax/current_user_capabilities.php');
  expect(response.ok()).toBeTruthy();
  const payload = await response.json();
  expect(payload.success).toBeTruthy();
  return payload.permissions as Record<string, boolean>;
}

export async function fetchPosSessionCapabilities(
  request: APIRequestContext,
): Promise<Record<string, boolean>> {
  const response = await request.get('/pos_session_status.php');
  expect(response.ok()).toBeTruthy();
  const payload = await response.json();
  return (payload.capabilities ?? {}) as Record<string, boolean>;
}

export async function postHandlerAsRole(
  page: Page,
  handlerPath: string,
  body: Record<string, string>,
): Promise<APIResponse> {
  return page.request.post(handlerPath, {
    form: body,
    failOnStatusCode: false,
    maxRedirects: 0,
  });
}

export async function assertDemoPersonaShape(
  page: Page,
  role: PersonaRole,
): Promise<Record<string, boolean>> {
  const permissions = await fetchCapabilities(page.request);

  if (role === 'admin') {
    expect(permissions['users.manage']).toBe(true);
    expect(permissions['roles.manage']).toBe(true);
    return permissions;
  }

  if (role === 'cashier' || role === 'waiter') {
    expect(permissions['users.manage'], `${role} demo user must not have users.manage — run tools/seed_demo_restaurant.php`).toBe(false);
    expect(permissions['roles.manage'], `${role} demo user must not have roles.manage`).toBe(false);
  }

  if (role === 'manager') {
    expect(permissions['menu.edit'], `${role} demo user should have menu.edit`).toBe(true);
    expect(permissions['users.manage'], `${role} demo user must not have users.manage`).toBe(false);
  }

  return permissions;
}
