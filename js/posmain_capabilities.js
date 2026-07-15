(function (global) {
    function can(permissionKey) {
        var caps = global.POSMAIN_CAPABILITIES || {};
        return caps[permissionKey] === true;
    }

    function limitRow(permissionKey) {
        var limits = global.POSMAIN_LIMITS || {};
        return limits[permissionKey] || null;
    }

    function checkAmountWithinLimit(permissionKey, amount) {
        var row = limitRow(permissionKey);
        if (!row || row.is_unlimited) {
            return true;
        }
        if (row.limit_value === null || row.limit_value === undefined || row.limit_value === '') {
            return true;
        }
        return parseFloat(amount) <= parseFloat(row.limit_value);
    }

    function amountExceedsLimit(permissionKey, amount) {
        return !checkAmountWithinLimit(permissionKey, amount);
    }

    function approverRolesFor(permissionKey) {
        var index = global.POSMAIN_APPROVER_ROLES || {};
        var rows = index[permissionKey];
        return Array.isArray(rows) ? rows : [];
    }

    function formatApproverRoleHint(permissionKey, options) {
        options = options || {};
        if (options.require_same_user) {
            return 'أدخل رمزك الشخصي لتأكيد العملية';
        }
        if (typeof options.roleHint === 'string' && options.roleHint.trim() !== '') {
            return options.roleHint.trim();
        }
        var roles = approverRolesFor(permissionKey);
        var names = [];
        for (var i = 0; i < roles.length; i++) {
            var name = roles[i] && (roles[i].name || roles[i].role_key);
            if (name && names.indexOf(name) === -1) {
                names.push(String(name));
            }
        }
        if (names.length === 0) {
            return 'يتطلب اعتماد مستخدم بصلاحية مناسبة';
        }
        if (names.length === 1) {
            return 'يتطلب صلاحية: ' + names[0];
        }
        return 'يتطلب صلاحية: ' + names.join(' أو ');
    }

    global.POSMAIN = global.POSMAIN || {};
    global.POSMAIN.can = can;
    global.POSMAIN.limit = limitRow;
    global.POSMAIN.checkAmountWithinLimit = checkAmountWithinLimit;
    global.POSMAIN.amountExceedsLimit = amountExceedsLimit;
    global.POSMAIN.approverRolesFor = approverRolesFor;
    global.POSMAIN.formatApproverRoleHint = formatApproverRoleHint;
})(window);
