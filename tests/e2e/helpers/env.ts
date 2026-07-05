export type PersonaRole = 'admin' | 'manager' | 'cashier' | 'waiter' | 'kitchen';

export const baseURL =
  process.env.POSMAIN_TEST_HTTP_BASE ||
  process.env.POSMAIN_LOCAL_POS_URL ||
  'http://127.0.0.1:8010';

export function personaCredentials(role: PersonaRole): { username: string; password: string } {
  const demoPassword = process.env.POSMAIN_E2E_DEMO_PASSWORD || 'P6demo123!';

  const envMap: Record<PersonaRole, { userKey: string; passKey: string; defaultUser: string }> = {
    admin: {
      userKey: 'POSMAIN_E2E_USER_ADMIN',
      passKey: 'POSMAIN_E2E_PASS_ADMIN',
      defaultUser: 'p6_admin',
    },
    manager: {
      userKey: 'POSMAIN_E2E_USER_MANAGER',
      passKey: 'POSMAIN_E2E_PASS_MANAGER',
      defaultUser: 'p6_manager',
    },
    cashier: {
      userKey: 'POSMAIN_E2E_USER_CASHIER',
      passKey: 'POSMAIN_E2E_PASS_CASHIER',
      defaultUser: 'p6_cashier',
    },
    waiter: {
      userKey: 'POSMAIN_E2E_USER_WAITER',
      passKey: 'POSMAIN_E2E_PASS_WAITER',
      defaultUser: 'p6_waiter',
    },
    kitchen: {
      userKey: 'POSMAIN_E2E_USER_KITCHEN',
      passKey: 'POSMAIN_E2E_PASS_KITCHEN',
      defaultUser: 'p6_kitchen',
    },
  };

  const config = envMap[role];
  return {
    username: process.env[config.userKey] || config.defaultUser,
    password: process.env[config.passKey] || demoPassword,
  };
}

export function skipIfHttpDown(): boolean {
  return process.env.POSMAIN_E2E_SKIP_IF_DOWN === '1';
}
