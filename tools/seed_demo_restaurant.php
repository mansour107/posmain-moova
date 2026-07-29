<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found\n";
    exit(1);
}

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/PasswordService.php';
require_once __DIR__ . '/../classes/Security/RolePermissionSyncService.php';

final class Phase6DemoSeeder
{
    private mysqli $db;
    /** @var array<string,mixed> */
    private array $config;
    /** @var array<string,bool> */
    private array $options;
    /** @var array<string,array<string,bool>> */
    private array $tableColumns = [];
    /** @var array<string,bool> */
    private array $existingTables = [];
    /** @var array<string,int> */
    private array $dryIds = [];
    /** @var array<string,int> */
    private array $counts = [
        'accounts' => 0,
        'categories' => 0,
        'items' => 0,
        'modifier_groups' => 0,
        'modifier_options' => 0,
        'item_modifier_groups' => 0,
        'table_areas' => 0,
        'tables' => 0,
        'roles' => 0,
        'users' => 0,
        'payment_methods' => 0,
        'settings' => 0,
        'moova_dummy_links' => 0,
        'reset_actions' => 0,
    ];
    /** @var list<string> */
    private array $warnings = [];
    private int $plannedStatements = 0;
    private int $appliedStatements = 0;
    /** @var array<string,int> */
    private array $accountIds = [];

    /**
     * @param array<string,mixed> $config
     * @param array<string,bool> $options
     */
    public function __construct(mysqli $db, array $config, array $options)
    {
        $this->db = $db;
        $this->config = $config;
        $this->options = $options;
    }

    /**
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $this->requireCoreTables();

        if (!$this->options['dry_run']) {
            $this->db->begin_transaction();
        }

        try {
            if ($this->options['reset_demo']) {
                $this->resetDemoRows();
            }

            $this->seedAccounts();
            $categoryIds = $this->seedCategories();
            $itemIds = $this->seedItems($categoryIds);
            $this->seedModifiers($itemIds);
            $areaIds = $this->seedTableAreas();
            $tableIds = $this->seedTables($areaIds);
            $this->seedPaymentMethods();
            $this->seedUsers();
            $this->seedSettings();
            $this->seedMoovaDummy($tableIds);

            if (!$this->options['dry_run']) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if (!$this->options['dry_run']) {
                $this->db->rollback();
            }

            throw $exception;
        }

        return [
            'ok' => true,
            'dry_run' => $this->options['dry_run'],
            'reset_demo' => $this->options['reset_demo'],
            'tenant' => $this->tenant(),
            'branch' => $this->branch(),
            'counts' => $this->counts,
            'planned_statements' => $this->plannedStatements,
            'applied_statements' => $this->appliedStatements,
            'warnings' => $this->warnings,
            'demo_credentials' => [
                'admin' => 'p6_admin',
                'manager' => 'p6_manager',
                'cashier' => 'p6_cashier',
                'waiter' => 'p6_waiter',
                'password' => 'P6demo123!',
            ],
        ];
    }

    private function requireCoreTables(): void
    {
        $required = ['acc_head', 'settings', 'item_group', 'myitems', 'tables', 'usr_pwrs', 'users'];
        $missing = [];
        foreach ($required as $table) {
            if (!$this->tableExists($table)) {
                $missing[] = $table;
            }
        }

        if ($missing !== []) {
            throw new RuntimeException('Missing required tables for Phase 6 demo seed: ' . implode(', ', $missing));
        }
    }

    private function resetDemoRows(): void
    {
        $this->softReset('item_group', 'gname', 'P6-DEMO%');
        $this->softReset('myitems', 'barcode', 'P6DEMO-%');
        $this->softReset('tables', 'tname', 'P6-DEMO-%');
        $this->softReset('usr_pwrs', 'rollname', 'P6 Demo%');
        $this->softReset('users', 'uname', 'p6_%');
        $this->deactivateByLike('table_areas', 'name_en', 'P6-DEMO%');
        $this->deactivateByLike('modifier_groups', 'name_en', 'P6-DEMO%');
        $this->deactivateByLike('modifier_options', 'name_en', 'P6-DEMO%');
        $this->deactivateByLike('payment_methods', 'code', 'p6_%');

        if ($this->tableExists('moova_pos_shop_links') && $this->hasColumn('moova_pos_shop_links', 'moova_branch_id')) {
            $this->execute(
                'UPDATE `moova_pos_shop_links` SET `status` = ? WHERE `moova_branch_id` = ?',
                ['inactive', 'p6-demo-branch']
            );
            $this->counts['reset_actions']++;
        }

        if ($this->tableExists('moova_pos_table_links') && $this->hasColumn('moova_pos_table_links', 'moova_branch_id')) {
            $this->execute(
                'UPDATE `moova_pos_table_links` SET `status` = ? WHERE `moova_branch_id` = ?',
                ['inactive', 'p6-demo-branch']
            );
            $this->counts['reset_actions']++;
        }
    }

    private function softReset(string $table, string $keyColumn, string $pattern): void
    {
        if (!$this->tableExists($table) || !$this->hasColumn($table, $keyColumn)) {
            return;
        }

        if ($this->hasColumn($table, 'isdeleted')) {
            $this->execute(
                sprintf('UPDATE `%s` SET `isdeleted` = 1 WHERE `%s` LIKE ?', $table, $keyColumn),
                [$pattern]
            );
            $this->counts['reset_actions']++;
            return;
        }

        $this->deactivateByLike($table, $keyColumn, $pattern);
    }

    private function deactivateByLike(string $table, string $keyColumn, string $pattern): void
    {
        if (!$this->tableExists($table) || !$this->hasColumn($table, $keyColumn)) {
            return;
        }

        if ($this->hasColumn($table, 'is_active')) {
            $this->execute(
                sprintf('UPDATE `%s` SET `is_active` = 0 WHERE `%s` LIKE ?', $table, $keyColumn),
                [$pattern]
            );
            $this->counts['reset_actions']++;
            return;
        }

        if ($this->hasColumn($table, 'status')) {
            $this->execute(
                sprintf('UPDATE `%s` SET `status` = ? WHERE `%s` LIKE ?', $table, $keyColumn),
                ['inactive', $pattern]
            );
            $this->counts['reset_actions']++;
        }
    }

    private function seedAccounts(): void
    {
        $accounts = [
            'supplier_parent' => ['code' => '211P6DEMO', 'aname' => 'P6-DEMO Suppliers', 'parent_id' => 0, 'is_stock' => 0, 'is_fund' => 0, 'is_basic' => 1],
            'supplier' => ['code' => '211P6DEMO001', 'aname' => 'P6-DEMO Default Supplier', 'parent_id' => 0, 'is_stock' => 0, 'is_fund' => 0, 'is_basic' => 0],
            'client' => ['code' => '122P6DEMO', 'aname' => 'P6-DEMO Walk-in Customer', 'parent_id' => 0, 'is_stock' => 0, 'is_fund' => 0, 'is_basic' => 0],
            'store' => ['code' => '123P6DEMO', 'aname' => 'P6-DEMO Main Store', 'parent_id' => 0, 'is_stock' => 1, 'is_fund' => 0, 'is_basic' => 0],
            'employee' => ['code' => '213P6DEMO', 'aname' => 'P6-DEMO Staff Clearing', 'parent_id' => 35, 'is_stock' => 0, 'is_fund' => 0, 'is_basic' => 0],
            'fund' => ['code' => '121P6DEMO', 'aname' => 'P6-DEMO Cash Drawer', 'parent_id' => 0, 'is_stock' => 0, 'is_fund' => 1, 'is_basic' => 0],
            'card_clearing' => ['code' => '121P6DEMOCARD', 'aname' => 'P6-DEMO Card Clearing', 'parent_id' => 0, 'is_stock' => 0, 'is_fund' => 0, 'is_basic' => 0],
            'wallet_clearing' => ['code' => '121P6DEMOWALLET', 'aname' => 'P6-DEMO Wallet Clearing', 'parent_id' => 0, 'is_stock' => 0, 'is_fund' => 0, 'is_basic' => 0],
        ];

        $supplierParentId = null;
        foreach ($accounts as $slot => $account) {
            $parentId = $account['parent_id'];
            if ($slot === 'supplier' && $supplierParentId !== null) {
                $parentId = $supplierParentId;
            }
            $id = $this->upsert('acc_head', 'code', $account['code'], [
                'code' => $account['code'],
                'aname' => $account['aname'],
                'parent_id' => $parentId,
                'is_stock' => $account['is_stock'],
                'is_fund' => $account['is_fund'],
                'is_basic' => $account['is_basic'],
                'isdeleted' => 0,
                'tenant' => $this->tenant(),
                'branch' => $this->branch(),
            ], 'accounts');
            if ($id !== null) {
                $this->accountIds[$slot] = $id;
                if ($slot === 'supplier_parent') {
                    $supplierParentId = $id;
                }
            }
        }
    }

    /**
     * @return array<string,int>
     */
    private function seedCategories(): array
    {
        $categories = [
            'coffee' => 'P6-DEMO Coffee',
            'bakery' => 'P6-DEMO Bakery',
            'meals' => 'P6-DEMO Meals',
        ];

        $ids = [];
        foreach ($categories as $key => $name) {
            $id = $this->upsert('item_group', 'gname', $name, [
                'gname' => $name,
                'isdeleted' => 0,
                'tenant' => $this->tenant(),
                'branch' => $this->branch(),
            ], 'categories');
            if ($id !== null) {
                $ids[$key] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<string,int> $categoryIds
     * @return array<string,int>
     */
    private function seedItems(array $categoryIds): array
    {
        $catalog = [
            'coffee' => [
                ['Espresso', 45.00], ['Double Espresso', 58.00], ['Americano', 52.00],
                ['Cappuccino', 68.00], ['Latte', 70.00], ['Flat White', 72.00],
                ['Mocha', 76.00], ['Caramel Latte', 80.00], ['Vanilla Latte', 80.00],
                ['Turkish Coffee', 48.00], ['Cold Brew', 78.00], ['Iced Americano', 62.00],
                ['Iced Latte', 75.00], ['Spanish Latte', 88.00], ['Hot Chocolate', 68.00],
                ['Chai Latte', 74.00], ['Matcha Latte', 90.00], ['Tea Pot', 42.00],
            ],
            'bakery' => [
                ['Butter Croissant', 46.00], ['Cheese Croissant', 54.00], ['Chocolate Croissant', 58.00],
                ['Almond Croissant', 66.00], ['Blueberry Muffin', 52.00], ['Chocolate Muffin', 52.00],
                ['Cinnamon Roll', 60.00], ['Banana Bread Slice', 44.00], ['Brownie', 48.00],
                ['Cheesecake Slice', 86.00], ['Carrot Cake Slice', 74.00], ['Apple Danish', 55.00],
                ['Mini Donut Box', 72.00], ['Bagel Cream Cheese', 64.00], ['Garlic Bread', 42.00],
                ['Sourdough Toast', 50.00], ['Date Cookie', 38.00], ['Honey Cake', 68.00],
            ],
            'meals' => [
                ['Chicken Caesar Salad', 132.00], ['Greek Salad', 118.00], ['Tuna Sandwich', 124.00],
                ['Turkey Sandwich', 132.00], ['Grilled Chicken Wrap', 138.00], ['Falafel Wrap', 96.00],
                ['Beef Burger', 168.00], ['Chicken Burger', 152.00], ['Margherita Pizza', 144.00],
                ['Pepperoni Pizza', 165.00], ['Penne Alfredo', 154.00], ['Penne Arrabbiata', 138.00],
                ['Chicken Rice Bowl', 148.00], ['Beef Rice Bowl', 166.00], ['Soup of the Day', 74.00],
                ['Kids Pasta', 86.00], ['Breakfast Platter', 142.00], ['Halloumi Plate', 128.00],
            ],
        ];

        $ids = [];
        $prefixes = ['coffee' => 'COF', 'bakery' => 'BAK', 'meals' => 'MEA'];
        foreach ($catalog as $category => $items) {
            $groupId = $categoryIds[$category] ?? 0;
            foreach ($items as $index => $item) {
                [$name, $price] = $item;
                $barcode = sprintf('P6DEMO-%s-%03d', $prefixes[$category], $index + 1);
                $id = $this->upsert('myitems', 'barcode', $barcode, [
                    'barcode' => $barcode,
                    'iname' => 'P6-DEMO ' . $name,
                    'price1' => $price,
                    'price2' => $price,
                    'price3' => $price,
                    'sprice' => $price,
                    'last_price' => $price,
                    'cost_price' => round($price * 0.42, 2),
                    'group1' => $groupId,
                    'itmqty' => 200,
                    'item_type' => 'sellable',
                    'track_stock' => $category === 'coffee' ? 0 : 1,
                    'info' => 'Phase 6 disposable demo item',
                    'isdeleted' => 0,
                    'tenant' => $this->tenant(),
                    'branch' => $this->branch(),
                ], 'items');
                if ($id !== null) {
                    $ids[$barcode] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * @param array<string,int> $itemIds
     */
    private function seedModifiers(array $itemIds): void
    {
        if (!$this->tableExists('modifier_groups') || !$this->tableExists('modifier_options')) {
            $this->warnings[] = 'Modifier tables are missing; skipped modifier seed.';
            return;
        }

        $groups = [
            'size' => ['name' => 'P6-DEMO Size', 'min' => 1, 'max' => 1, 'required' => 1],
            'milk' => ['name' => 'P6-DEMO Milk', 'min' => 0, 'max' => 1, 'required' => 0],
            'addons' => ['name' => 'P6-DEMO Add-ons', 'min' => 0, 'max' => 4, 'required' => 0],
        ];
        $groupIds = [];
        foreach ($groups as $key => $group) {
            $id = $this->upsert('modifier_groups', 'name_en', $group['name'], [
                'name_ar' => $group['name'],
                'name_en' => $group['name'],
                'selection_min' => $group['min'],
                'selection_max' => $group['max'],
                'is_required' => $group['required'],
                'is_active' => 1,
                'sort_order' => count($groupIds) + 1,
                'tenant' => $this->tenant(),
                'branch' => $this->branch(),
            ], 'modifier_groups');
            if ($id !== null) {
                $groupIds[$key] = $id;
            }
        }

        $options = [
            'size' => [['Small', 0.00], ['Medium', 8.00], ['Large', 14.00]],
            'milk' => [['Whole Milk', 0.00], ['Skim Milk', 0.00], ['Oat Milk', 18.00]],
            'addons' => [['Extra Shot', 16.00], ['Caramel', 12.00], ['Extra Cheese', 18.00], ['Spicy Sauce', 7.00]],
        ];

        foreach ($options as $groupKey => $rows) {
            $groupId = $groupIds[$groupKey] ?? null;
            if ($groupId === null) {
                continue;
            }
            foreach ($rows as $sort => $row) {
                [$name, $delta] = $row;
                $optionName = 'P6-DEMO ' . $name;
                $this->upsert('modifier_options', 'name_en', $optionName, [
                    'group_id' => $groupId,
                    'name_ar' => $optionName,
                    'name_en' => $optionName,
                    'price_delta' => $delta,
                    'is_active' => 1,
                    'sort_order' => $sort + 1,
                    'tenant' => $this->tenant(),
                    'branch' => $this->branch(),
                ], 'modifier_options');
            }
        }

        foreach ($itemIds as $barcode => $itemId) {
            if (str_starts_with($barcode, 'P6DEMO-COF-')) {
                $this->linkItemModifierGroup($itemId, $groupIds['size'] ?? null);
                $this->linkItemModifierGroup($itemId, $groupIds['milk'] ?? null);
                $this->linkItemModifierGroup($itemId, $groupIds['addons'] ?? null);
            } elseif (str_starts_with($barcode, 'P6DEMO-BAK-') || str_starts_with($barcode, 'P6DEMO-MEA-')) {
                $this->linkItemModifierGroup($itemId, $groupIds['addons'] ?? null);
            }
        }
    }

    /**
     * @return array<string,int>
     */
    private function seedTableAreas(): array
    {
        if (!$this->tableExists('table_areas')) {
            $this->warnings[] = 'table_areas is missing; seeded tables without area ids.';
            return [];
        }

        $areas = [
            'main' => ['P6-DEMO Main Hall', 1],
            'patio' => ['P6-DEMO Patio', 2],
        ];
        $ids = [];
        foreach ($areas as $key => $area) {
            [$name, $sort] = $area;
            $id = $this->upsert('table_areas', 'name_en', $name, [
                'name_ar' => $name,
                'name_en' => $name,
                'is_active' => 1,
                'sort_order' => $sort,
                'tenant' => $this->tenant(),
                'branch' => $this->branch(),
            ], 'table_areas');
            if ($id !== null) {
                $ids[$key] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<string,int> $areaIds
     * @return array<string,int>
     */
    private function seedTables(array $areaIds): array
    {
        $ids = [];
        for ($i = 1; $i <= 20; $i++) {
            $areaKey = $i <= 12 ? 'main' : 'patio';
            $name = sprintf('P6-DEMO-%s-%02d', strtoupper($areaKey), $areaKey === 'main' ? $i : $i - 12);
            $id = $this->upsert('tables', 'tname', $name, [
                'tname' => $name,
                'table_case' => 0,
                'area_id' => $areaIds[$areaKey] ?? 0,
                'capacity' => $i % 5 === 0 ? 6 : 4,
                'pos_x' => (($i - 1) % 5) * 120,
                'pos_y' => intdiv($i - 1, 5) * 100,
                'shape' => $i % 5 === 0 ? 'round' : 'square',
                'display_order' => $i,
                'isdeleted' => 0,
                'tenant' => $this->tenant(),
                'branch' => $this->branch(),
            ], 'tables');
            if ($id !== null) {
                $ids[$name] = $id;
            }
        }

        return $ids;
    }

    private function seedPaymentMethods(): void
    {
        if (!$this->tableExists('payment_methods')) {
            $this->warnings[] = 'payment_methods is missing; skipped payment method seed.';
            return;
        }

        $methods = [
            ['p6_cash', 'P6-DEMO Cash', 'cash', 'cash_drawer', 'fund', 1],
            ['p6_card', 'P6-DEMO Card', 'card', 'reference_required', 'card_clearing', 2],
            ['p6_wallet', 'P6-DEMO Wallet', 'wallet', 'reference_required', 'wallet_clearing', 3],
        ];

        foreach ($methods as $method) {
            [$code, $name, $type, $settlementPolicy, $accountSlot, $sort] = $method;
            $this->upsert('payment_methods', 'code', $code, [
                'code' => $code,
                'name_ar' => $name,
                'name_en' => $name,
                'type' => $type,
                'account_id' => $this->accountIds[$accountSlot] ?? null,
                'requires_reference' => $settlementPolicy === 'reference_required' ? 1 : 0,
                'settlement_policy' => $settlementPolicy,
                'is_active' => 1,
                'sort_order' => $sort,
                'tenant' => $this->tenant(),
                'branch' => $this->branch(),
            ], 'payment_methods');
        }
    }

    private function seedUsers(): void
    {
        // Keep P6-labelled roles for older QA fixtures, but attach demo users to the
        // maintained presets. Capability records are authoritative when present, so
        // hand-made legacy roles can otherwise login but lack modern permissions.
        $roleIds = [
            'admin' => $this->seedRole('P6 Demo Admin', ['show_users', 'add_users', 'edit_users', 'delete_users', 'show_sales', 'add_sales', 'edit_sales', 'delete_sales', 'show_payment', 'add_payment', 'edit_payment', 'delete_payment', 'show_items', 'add_items', 'edit_items', 'delete_items']),
            'manager' => $this->seedRole('P6 Demo Manager', ['show_sales', 'add_sales', 'edit_sales', 'delete_sales', 'show_payment', 'add_payment', 'edit_payment', 'delete_payment', 'show_items', 'add_items', 'edit_items']),
            'cashier' => $this->seedRole('P6 Demo Cashier', ['show_sales', 'add_sales', 'show_payment', 'add_payment', 'show_items']),
            'waiter' => $this->seedRole('P6 Demo Waiter', ['show_sales', 'add_sales', 'show_items']),
        ];

        $roleColumns = $this->tableColumnsFor('usr_pwrs');
        if (isset($roleColumns['role_key'], $roleColumns['is_system'])) {
            $presetRoleIds = RolePermissionSyncService::seedPresetRoles($this->db);
            $roleIds = [
                'admin' => (int) ($presetRoleIds['owner'] ?? 0),
                'manager' => (int) ($presetRoleIds['manager'] ?? 0),
                'cashier' => (int) ($presetRoleIds['cashier'] ?? 0),
                'waiter' => (int) ($presetRoleIds['waiter'] ?? 0),
            ];
            foreach ($roleIds as $role => $roleId) {
                if ($roleId < 1) {
                    throw new RuntimeException('PRESET_ROLE_SEED_FAILED:' . $role);
                }
            }
        }

        $passwordHash = PasswordService::hashPassword('P6demo123!');
        $users = [
            ['p6_admin', 'Phase 6 Admin', 'admin', 0],
            ['p6_manager', 'Phase 6 Manager', 'manager', 0],
            ['p6_cashier', 'Phase 6 Cashier', 'cashier', 0],
            ['p6_waiter', 'Phase 6 Waiter', 'waiter', 1],
        ];

        foreach ($users as $user) {
            [$username, $name, $role, $isWaiter] = $user;
            $this->upsert('users', 'uname', $username, [
                'uname' => $username,
                'name' => $name,
                'password' => $passwordHash,
                'usertype' => $role === 'admin' ? 2 : 1,
                'userrole' => $roleIds[$role] ?? 0,
                'is_waiter' => $isWaiter,
                'img' => '',
                'isdeleted' => 0,
                'tenant' => $this->tenant(),
                'branch' => $this->branch(),
            ], 'users');
        }
    }

    /**
     * @param list<string> $enabledColumns
     */
    private function seedRole(string $roleName, array $enabledColumns): ?int
    {
        $data = [
            'rollname' => $roleName,
            'is_active' => 1,
            'isdeleted' => 0,
            'tenant' => $this->tenant(),
            'branch' => $this->branch(),
        ];
        foreach ($this->roleFlagColumns() as $column) {
            $data[$column] = 0;
        }
        foreach ($enabledColumns as $column) {
            $data[$column] = 1;
        }

        return $this->upsert('usr_pwrs', 'rollname', $roleName, $data, 'roles');
    }

    /**
     * @return list<string>
     */
    private function roleFlagColumns(): array
    {
        if (!$this->tableExists('usr_pwrs')) {
            return RolePermissionSyncService::allManagedLegacyColumns();
        }

        // Role metadata is not a legacy permission flag. In particular, role_key
        // is unique and assigning it the numeric permission default would make
        // the second seeded role fail with a duplicate-key error.
        $skip = ['id', 'rollname', 'info', 'isdeleted', 'is_active', 'tenant', 'branch', 'role_key', 'is_system'];
        $columns = [];
        foreach (array_keys($this->tableColumnsFor('usr_pwrs')) as $column) {
            if (in_array($column, $skip, true)) {
                continue;
            }
            $columns[] = $column;
        }

        return $columns;
    }

    /**
     * @return array<string,bool>
     */
    private function tableColumnsFor(string $table): array
    {
        if (!isset($this->tableColumns[$table])) {
            $this->hasColumn($table, 'id');
        }

        return $this->tableColumns[$table] ?? [];
    }

    private function seedSettings(): void
    {
        $this->ensureSettingsRow();
        $defaults = [
            'def_pos_client' => $this->accountIds['client'] ?? null,
            'def_pos_store' => $this->accountIds['store'] ?? null,
            'def_pos_employee' => $this->accountIds['employee'] ?? null,
            'def_pos_fund' => $this->accountIds['fund'] ?? null,
        ];

        foreach ($defaults as $column => $value) {
            if ($value === null || !$this->hasColumn('settings', $column)) {
                continue;
            }
            $this->execute(
                sprintf('UPDATE `settings` SET `%s` = ? WHERE `id` = 1 AND (`%s` IS NULL OR `%s` = 0)', $column, $column, $column),
                [$value]
            );
            $this->counts['settings']++;
        }

        if ($this->hasColumn('settings', 'pos_has_password')) {
            $this->execute(
                'UPDATE `settings` SET `pos_has_password` = 1 WHERE `id` = 1 AND (`pos_has_password` IS NULL OR `pos_has_password` = 0)',
                []
            );
            $this->counts['settings']++;
        }
    }

    private function ensureSettingsRow(): void
    {
        $exists = (int)$this->scalar('SELECT COUNT(*) FROM `settings` WHERE `id` = 1', []) > 0;
        if ($exists) {
            return;
        }

        $data = $this->filterData('settings', [
            'id' => 1,
            'company_name' => 'P6-DEMO Restaurant',
            'isdeleted' => 0,
            'tenant' => $this->tenant(),
            'branch' => $this->branch(),
        ]);

        if ($data === []) {
            return;
        }

        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `settings` (`%s`) VALUES (%s)',
            implode('`, `', $columns),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        $this->execute($sql, array_values($data));
        $this->counts['settings']++;
    }

    /**
     * @param array<string,int> $tableIds
     */
    private function seedMoovaDummy(array $tableIds): void
    {
        if (!$this->options['with_moova_dummy']) {
            return;
        }

        if (!$this->tableExists('moova_pos_shop_links')) {
            $this->warnings[] = 'moova_pos_shop_links is missing; skipped Moova dummy link.';
            return;
        }

        if ($this->findByColumn('moova_pos_shop_links', 'moova_branch_id', 'p6-demo-branch') === null
            && $this->hasColumn('moova_pos_shop_links', 'pos_tenant')
            && $this->hasColumn('moova_pos_shop_links', 'pos_branch')
            && $this->hasColumn('moova_pos_shop_links', 'status')
        ) {
            $activeForScope = (int)$this->scalar(
                'SELECT COUNT(*) FROM `moova_pos_shop_links` WHERE `pos_tenant` = ? AND `pos_branch` = ? AND `status` = ?',
                [(int)$this->tenant(), (int)$this->branch(), 'active']
            );
            if ($activeForScope > 0) {
                $this->warnings[] = 'Active Moova link already exists for this tenant/branch; skipped P6 dummy link.';
                return;
            }
        }

        $linkId = $this->upsert('moova_pos_shop_links', 'moova_branch_id', 'p6-demo-branch', [
            'moova_shop_id' => 'p6-demo-shop',
            'moova_branch_id' => 'p6-demo-branch',
            'pos_tenant' => $this->tenant(),
            'pos_branch' => $this->branch(),
            'moova_device_token_hash' => hash('sha256', 'p6-demo-device-token'),
            'moova_device_token_last4' => 'oken',
            'device_token_hash' => hash('sha256', 'p6-demo-device-token'),
            'device_token_last4' => 'oken',
            'widget_url' => 'http://127.0.0.1:3001/pos-widget',
            'locale' => 'ar',
            'status' => 'active',
        ], 'moova_dummy_links');

        if ($linkId === null || !$this->tableExists('moova_pos_table_links')) {
            return;
        }

        foreach ($tableIds as $name => $tableId) {
            if (!$this->hasColumn('moova_pos_table_links', 'pos_table_id') || !$this->hasColumn('moova_pos_table_links', 'moova_branch_id')) {
                return;
            }

            $existingId = $this->findByColumns('moova_pos_table_links', [
                'pos_table_id' => $tableId,
                'moova_branch_id' => 'p6-demo-branch',
            ]);
            $payload = [
                'shop_link_id' => $linkId,
                'pos_table_id' => $tableId,
                'moova_branch_id' => 'p6-demo-branch',
                'moova_table_id' => strtolower(str_replace('P6-DEMO-', 'p6-demo-', $name)),
                'pos_tenant' => $this->tenant(),
                'pos_branch' => $this->branch(),
                'status' => 'active',
            ];
            if ($existingId !== null) {
                $this->updateById('moova_pos_table_links', $existingId, $payload);
            } else {
                $this->insertRow('moova_pos_table_links', $payload);
            }
        }
    }

    private function linkItemModifierGroup(int $itemId, ?int $groupId): void
    {
        if ($groupId === null || !$this->tableExists('item_modifier_groups')) {
            return;
        }

        $existingId = $this->findByColumns('item_modifier_groups', [
            'item_id' => $itemId,
            'group_id' => $groupId,
        ]);

        if ($existingId !== null) {
            $this->counts['item_modifier_groups']++;
            return;
        }

        $this->insertRow('item_modifier_groups', [
            'item_id' => $itemId,
            'group_id' => $groupId,
            'sort_order' => 0,
        ]);
        $this->counts['item_modifier_groups']++;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function upsert(string $table, string $keyColumn, mixed $keyValue, array $data, string $counterKey): ?int
    {
        if (!$this->tableExists($table)) {
            $this->warnings[] = sprintf('%s is missing; skipped %s seed.', $table, $counterKey);
            return null;
        }

        if (!$this->hasColumn($table, $keyColumn)) {
            $this->warnings[] = sprintf('%s.%s is missing; skipped %s seed.', $table, $keyColumn, $counterKey);
            return null;
        }

        $data[$keyColumn] = $keyValue;
        $data = $this->filterData($table, $data);
        $existingId = $this->findByColumn($table, $keyColumn, $keyValue);
        $this->counts[$counterKey]++;

        if ($this->options['dry_run']) {
            $this->plannedStatements++;
            return $existingId ?? $this->nextDryId($table);
        }

        if ($existingId !== null) {
            $this->updateById($table, $existingId, $data);
            return $existingId;
        }

        return $this->insertRow($table, $data);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function insertRow(string $table, array $data): ?int
    {
        $data = $this->filterData($table, $data);
        if ($data === []) {
            return null;
        }

        if ($this->options['dry_run']) {
            $this->plannedStatements++;
            return $this->nextDryId($table);
        }

        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $this->identifier($table),
            implode('`, `', array_map([$this, 'identifier'], $columns)),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        $this->execute($sql, array_values($data));

        return (int)$this->db->insert_id;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function updateById(string $table, int $id, array $data): void
    {
        $data = $this->filterData($table, $data);
        unset($data['id']);
        if ($data === [] || !$this->hasColumn($table, 'id')) {
            return;
        }

        if ($this->options['dry_run']) {
            $this->plannedStatements++;
            return;
        }

        $assignments = [];
        foreach (array_keys($data) as $column) {
            $assignments[] = sprintf('`%s` = ?', $this->identifier($column));
        }
        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE `id` = ?',
            $this->identifier($table),
            implode(', ', $assignments)
        );
        $params = array_values($data);
        $params[] = $id;
        $this->execute($sql, $params);
    }

    /**
     * @param list<mixed> $params
     */
    private function execute(string $sql, array $params): void
    {
        if ($this->options['dry_run']) {
            $this->plannedStatements++;
            return;
        }

        $statement = $this->db->prepare($sql);
        if ($params !== []) {
            $types = $this->bindTypes($params);
            $statement->bind_param($types, ...$params);
        }
        $statement->execute();
        $statement->close();
        $this->appliedStatements++;
    }

    /**
     * @param list<mixed> $params
     */
    private function scalar(string $sql, array $params): mixed
    {
        $statement = $this->db->prepare($sql);
        if ($params !== []) {
            $types = $this->bindTypes($params);
            $statement->bind_param($types, ...$params);
        }
        $statement->execute();
        $result = $statement->get_result();
        $row = $result->fetch_row();
        $statement->close();

        return $row[0] ?? null;
    }

    private function findByColumn(string $table, string $column, mixed $value): ?int
    {
        if (!$this->hasColumn($table, 'id')) {
            return null;
        }

        $sql = sprintf(
            'SELECT `id` FROM `%s` WHERE `%s` = ? LIMIT 1',
            $this->identifier($table),
            $this->identifier($column)
        );
        $value = $this->scalar($sql, [$value]);

        return $value === null ? null : (int)$value;
    }

    /**
     * @param array<string,mixed> $criteria
     */
    private function findByColumns(string $table, array $criteria): ?int
    {
        if (!$this->tableExists($table) || !$this->hasColumn($table, 'id')) {
            return null;
        }

        $where = [];
        $params = [];
        foreach ($criteria as $column => $value) {
            if (!$this->hasColumn($table, $column)) {
                return null;
            }
            $where[] = sprintf('`%s` = ?', $this->identifier($column));
            $params[] = $value;
        }

        $sql = sprintf(
            'SELECT `id` FROM `%s` WHERE %s LIMIT 1',
            $this->identifier($table),
            implode(' AND ', $where)
        );
        $value = $this->scalar($sql, $params);

        return $value === null ? null : (int)$value;
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->existingTables)) {
            return $this->existingTables[$table];
        }

        $sql = 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?';
        $exists = (int)$this->scalar($sql, [$table]) > 0;
        $this->existingTables[$table] = $exists;

        return $exists;
    }

    private function hasColumn(string $table, string $column): bool
    {
        if (!isset($this->tableColumns[$table])) {
            $statement = $this->db->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
            );
            $statement->bind_param('s', $table);
            $statement->execute();
            $result = $statement->get_result();
            $columns = [];
            while ($row = $result->fetch_assoc()) {
                $columns[$row['COLUMN_NAME']] = true;
            }
            $statement->close();
            $this->tableColumns[$table] = $columns;
        }

        return isset($this->tableColumns[$table][$column]);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function filterData(string $table, array $data): array
    {
        $filtered = [];
        foreach ($data as $column => $value) {
            if ($this->hasColumn($table, (string)$column)) {
                $filtered[(string)$column] = $value;
            }
        }

        return $filtered;
    }

    /**
     * @param list<mixed> $params
     */
    private function bindTypes(array $params): string
    {
        $types = '';
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }

        return $types;
    }

    private function nextDryId(string $table): int
    {
        $this->dryIds[$table] = ($this->dryIds[$table] ?? 900000) + 1;

        return $this->dryIds[$table];
    }

    private function tenant(): int|string
    {
        $branchConfig = is_array($this->config['branch'] ?? null) ? $this->config['branch'] : [];
        if (array_key_exists('pos_tenant', $branchConfig) && $branchConfig['pos_tenant'] !== null) {
            return (int)$branchConfig['pos_tenant'];
        }

        return $this->config['tenant']['id'] ?? 0;
    }

    private function branch(): int|string
    {
        $branchConfig = is_array($this->config['branch'] ?? null) ? $this->config['branch'] : [];
        if (array_key_exists('pos_branch', $branchConfig) && $branchConfig['pos_branch'] !== null) {
            return (int)$branchConfig['pos_branch'];
        }

        return $this->config['branch']['id'] ?? 0;
    }

    private function identifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('Invalid SQL identifier: ' . $name);
        }

        return $name;
    }
}

/**
 * @return array<string,bool>
 */
function phase6_demo_seed_options(): array
{
    $options = getopt('', ['help', 'json', 'dry-run', 'apply', 'reset-demo', 'with-moova-dummy', 'no-moova-dummy', 'shop-id::']);
    if ($options === false) {
        $options = [];
    }

    if (isset($options['help'])) {
        echo phase6_demo_seed_help();
        exit(0);
    }

    if (isset($options['apply']) && isset($options['dry-run'])) {
        fwrite(STDERR, "Use either --apply or --dry-run, not both.\n");
        exit(1);
    }

    return [
        'json' => isset($options['json']),
        'dry_run' => !isset($options['apply']),
        'apply' => isset($options['apply']),
        'reset_demo' => isset($options['reset-demo']),
        'with_moova_dummy' => isset($options['with-moova-dummy']),
        'no_moova_dummy' => isset($options['no-moova-dummy']),
        'shop_id' => max(0, (int) ($options['shop-id'] ?? 0)),
    ];
}

function phase6_demo_seed_help(): string
{
    return <<<TXT
Usage: php tools/seed_demo_restaurant.php [--dry-run|--apply] [--reset-demo] [--with-moova-dummy] [--shop-id=ID] [--json]

Seeds a disposable Phase 6 pilot QA restaurant dataset:
- 3 categories, 54 items, 10 modifier options
- 20 tables across 2 areas
- admin, manager, cashier, and waiter demo users
- cash, card, and wallet payment methods
- POS default accounts/settings when missing

Safety:
- Defaults to --dry-run.
- Refuses production mode.
- --reset-demo only resets rows with P6-DEMO or p6_ prefixes before reseeding.
- --shop-id seeds one active routed shop through its configured database connection.

TXT;
}

function phase6_demo_seed_refuse_production(array $config): void
{
    $env = strtolower((string)($config['env'] ?? 'local'));
    $productionMode = (bool)($config['production_mode'] ?? false);
    if ($productionMode || $env === 'production' || $env === 'prod') {
        fwrite(STDERR, "Refusing to seed Phase 6 demo data while production mode/environment is active.\n");
        exit(2);
    }
}

function phase6_demo_seed_print(array $result, bool $json): void
{
    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return;
    }

    echo ($result['dry_run'] ? 'Dry run complete.' : 'Phase 6 demo seed complete.') . PHP_EOL;
    echo 'Tenant/branch: ' . $result['tenant'] . '/' . $result['branch'] . PHP_EOL;
    foreach ($result['counts'] as $label => $count) {
        echo sprintf('- %s: %d', $label, $count) . PHP_EOL;
    }
    if ($result['warnings'] !== []) {
        echo 'Warnings:' . PHP_EOL;
        foreach ($result['warnings'] as $warning) {
            echo '- ' . $warning . PHP_EOL;
        }
    }
    echo 'Demo users: p6_admin, p6_manager, p6_cashier, p6_waiter / P6demo123!' . PHP_EOL;
}

$seedOptions = phase6_demo_seed_options();
$appConfig = posmain_app_config();
phase6_demo_seed_refuse_production($appConfig);

if (!$seedOptions['no_moova_dummy']) {
    $env = strtolower((string)($appConfig['env'] ?? 'local'));
    $seedOptions['with_moova_dummy'] = $seedOptions['with_moova_dummy'] || $env === 'test';
}

try {
    $targetShopId = (int) ($seedOptions['shop_id'] ?? 0);
    if ($targetShopId > 0) {
        if (!posmain_router_enabled($appConfig)) {
            throw new RuntimeException('--shop-id requires router mode.');
        }
        $connection = posmain_shop_db_connect($targetShopId, $appConfig);
    } else {
        $connection = posmain_db_connect();
    }
    $seeder = new Phase6DemoSeeder($connection, $appConfig, $seedOptions);
    $result = $seeder->run();
    phase6_demo_seed_print($result, $seedOptions['json']);
    exit(0);
} catch (Throwable $exception) {
    if ($seedOptions['json']) {
        echo json_encode([
            'ok' => false,
            'error' => $exception->getMessage(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    } else {
        fwrite(STDERR, 'Phase 6 demo seed failed: ' . $exception->getMessage() . PHP_EOL);
    }
    exit(1);
}
