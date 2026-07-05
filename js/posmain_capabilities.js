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

    global.POSMAIN = global.POSMAIN || {};
    global.POSMAIN.can = can;
    global.POSMAIN.limit = limitRow;
    global.POSMAIN.checkAmountWithinLimit = checkAmountWithinLimit;
    global.POSMAIN.amountExceedsLimit = amountExceedsLimit;
})(window);
