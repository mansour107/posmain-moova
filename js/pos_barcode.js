/**
 * POS Barcode System - JavaScript
 * نظام نقاط البيع بالباركود
 */

$(document).ready(function() {
    // ========================================
    // Initialize on page load - Update totals if items exist (edit mode)
    // ========================================
    if ($('#itemData .item-card-order').length > 0) {
        updateItemCount();
        updateTotal();
    }
    const $filterInput = $('#posUnifiedSearch').length ? $('#posUnifiedSearch') : $('#itemFilterInput');
    const $itemsGrid = $('#itemsGrid');
    const $itemsGridLoader = $('#itemsGridLoader');
    const $currentControls = $('.pos-current-order-controls');
    const itemVariantCache = new Map();
    if ($currentControls.length) {
        $currentControls.find('.pos-customer-mount').append($('.pos-customer-field'));
        $currentControls.find('.pos-table-mount').append($('.pos-table-field'));
    }

    const $customerSelect = $('select[name="acc2_id"]');
    const $paymentFundSelect = $('#payment_fund_id');
    const $paymentBankSelect = $('#payment_bank_id');
    let initialCustomerId = '';
    let customerOptionsLoadStarted = false;
    let bankOptionsLoadStarted = false;

    function customerOptionExists(customerId) {
        const normalizedCustomerId = String(customerId || '');
        if (!normalizedCustomerId) {
            return false;
        }

        return $customerSelect.find('option').filter(function() {
            return String($(this).val()) === normalizedCustomerId;
        }).length > 0;
    }

    function getCustomerIdByName(customerName) {
        const normalizedCustomerName = String(customerName || '').trim();
        if (!normalizedCustomerName) {
            return '';
        }

        const $matchingOption = $customerSelect.find('option').filter(function() {
            return String($(this).text()).trim() === normalizedCustomerName;
        }).first();

        return $matchingOption.length ? String($matchingOption.val()) : '';
    }

    function getTableDefaultCustomerId() {
        const configuredCustomerId = String($customerSelect.attr('data-table-default-customer-id') || '');
        if (customerOptionExists(configuredCustomerId)) {
            return configuredCustomerId;
        }

        return getCustomerIdByName('العميل الافتراضي');
    }

    function selectCustomerById(customerId) {
        if (!$customerSelect.length) {
            return;
        }

        const normalizedCustomerId = String(customerId || '');
        if (!normalizedCustomerId) {
            $customerSelect.val('').trigger('change');
            return;
        }

        if (customerOptionExists(normalizedCustomerId)) {
            $customerSelect.val(normalizedCustomerId).trigger('change');
        }
    }

    const currentCustomerId = String($customerSelect.val() || '');
    const configuredInitialCustomerId = String($customerSelect.attr('data-initial-customer-id') || '');
    if (customerOptionExists(currentCustomerId)) {
        initialCustomerId = currentCustomerId;
    } else if (customerOptionExists(configuredInitialCustomerId)) {
        initialCustomerId = configuredInitialCustomerId;
    }

    function setTableDefaultCustomer() {
        if ($('#edit_order_id').val()) {
            return;
        }

        selectCustomerById(getTableDefaultCustomerId());
    }

    function restoreInitialCustomer() {
        if ($('#edit_order_id').val()) {
            return;
        }

        selectCustomerById(initialCustomerId);
    }

    if ($('#age2').is(':checked')) {
        setTableDefaultCustomer();
    }

    function mergeSelectOptions($select, options, selectedValue, placeholderHtml) {
        if (!$select.length) {
            return;
        }

        const normalizedSelectedValue = String(selectedValue || $select.val() || '');
        const existingOptions = new Map();
        $select.find('option').each(function() {
            const value = String($(this).val() || '');
            if (value) {
                existingOptions.set(value, String($(this).text()).trim());
            }
        });

        (options || []).forEach(function(option) {
            const value = String(option && option.id ? option.id : '');
            const label = String(option && option.name ? option.name : '').trim();
            if (value && label) {
                existingOptions.set(value, label);
            }
        });

        const html = [];
        if (placeholderHtml) {
            html.push(placeholderHtml);
        }

        existingOptions.forEach(function(label, value) {
            html.push($('<option>', { value: value, text: label }).prop('outerHTML'));
        });

        $select.html(html.join(''));
        if (normalizedSelectedValue && existingOptions.has(normalizedSelectedValue)) {
            $select.val(normalizedSelectedValue);
        }
    }

    function loadCustomerOptions() {
        if (!$customerSelect.length || customerOptionsLoadStarted || $customerSelect.attr('data-options-loaded') === '1') {
            return;
        }

        customerOptionsLoadStarted = true;
        const selectedValue = String($customerSelect.val() || initialCustomerId || '');
        $.ajax({
            url: 'ajax/get_pos_options.php',
            method: 'GET',
            dataType: 'json',
            cache: false,
            data: { type: 'customers' },
            success: function(response) {
                if (!response || response.success !== true) {
                    return;
                }

                mergeSelectOptions($customerSelect, response.options || [], selectedValue);
                $customerSelect.attr('data-options-loaded', '1');
                if (selectedValue) {
                    selectCustomerById(selectedValue);
                }
            },
            complete: function() {
                customerOptionsLoadStarted = false;
            }
        });
    }

    function syncPaymentFundOptions() {
        const $mainFundSelect = $('select[name="fund_id"]');
        if (!$mainFundSelect.length || !$paymentFundSelect.length) {
            return;
        }

        $paymentFundSelect.html($mainFundSelect.html());
        const selectedFund = String($mainFundSelect.val() || '');
        if (selectedFund) {
            $paymentFundSelect.val(selectedFund);
        }
    }

    function loadBankOptions() {
        if (!$paymentBankSelect.length || bankOptionsLoadStarted || $paymentBankSelect.attr('data-options-loaded') === '1') {
            return;
        }

        bankOptionsLoadStarted = true;
        const selectedValue = String($paymentBankSelect.val() || '');
        $.ajax({
            url: 'ajax/get_pos_options.php',
            method: 'GET',
            dataType: 'json',
            cache: false,
            data: { type: 'banks' },
            success: function(response) {
                if (!response || response.success !== true) {
                    return;
                }

                mergeSelectOptions($paymentBankSelect, response.options || [], selectedValue, '<option value="">-- اختر البنك --</option>');
                $paymentBankSelect.attr('data-options-loaded', '1');
            },
            complete: function() {
                bankOptionsLoadStarted = false;
            }
        });
    }

    window.POSMainSyncPaymentOptions = function() {
        syncPaymentFundOptions();
        loadBankOptions();
    };

    function activeCategoryFilter() {
        const $activeCategory = $('.category-btn.active').first();
        return {
            categoryId: $activeCategory.data('category'),
            keywords: String($activeCategory.data('keywords') || '')
                .split(',')
                .map(keyword => keyword.trim().toLowerCase())
                .filter(Boolean)
        };
    }

    function itemMatchesActiveFilter($item, searchText, categoryFilter) {
        const $card = $item.find('.item-card');
        const itemName = String($card.data('item-name') || '').toLowerCase();
        const itemBarcode = String($card.data('item-barcode') || '').toLowerCase();
        const categoryId = categoryFilter.categoryId;

        if (searchText && !itemName.includes(searchText) && !itemBarcode.includes(searchText)) {
            return false;
        }

        if (!categoryId || categoryId === 'all') {
            return true;
        }

        if (categoryFilter.keywords.length > 0) {
            return categoryFilter.keywords.some(keyword => itemName.includes(keyword));
        }

        return String($item.data('category')) === String(categoryId);
    }

    function normalizeItemId(itemId) {
        const normalized = String(itemId || '').trim();
        return normalized === '0' ? '' : normalized;
    }

    function itemHasVariantsValue(value) {
        return value === true || value === 1 || String(value || '') === '1' || String(value || '').toLowerCase() === 'true';
    }

    function cacheItemVariants(itemId, variants) {
        const key = normalizeItemId(itemId);
        if (!key || !Array.isArray(variants)) {
            return false;
        }

        itemVariantCache.set(key, variants);
        return true;
    }

    function cachedItemVariants(itemId) {
        const key = normalizeItemId(itemId);
        return key && itemVariantCache.has(key) ? itemVariantCache.get(key) : null;
    }

    function registerVariantCacheFromItem(item) {
        if (item && itemHasVariantsValue(item.has_variants) && Array.isArray(item.variants)) {
            cacheItemVariants(item.id, item.variants);
        }
    }

    function registerVariantCacheFromCard($card) {
        const itemId = $card.data('item-id');
        const rawVariants = $card.attr('data-variants');
        if (!rawVariants) {
            return;
        }

        try {
            const variants = JSON.parse(rawVariants);
            cacheItemVariants(itemId, variants);
        } catch (error) {
            console.warn('Unable to parse preloaded item variants:', error);
        }
    }

    function itemAvailabilityContext($card) {
        const isAvailable = String($card.attr('data-is-available') || '1') !== '0';
        const canAdd = String($card.attr('data-availability-can-add') || (isAvailable ? '1' : '0')) !== '0';
        return {
            isAvailable: isAvailable,
            canAdd: canAdd,
            status: String($card.attr('data-availability-status') || (isAvailable ? 'available' : 'manual_unavailable')),
            reason: String($card.attr('data-unavailable-reason') || '').trim(),
            requiresManagerOverride: String($card.attr('data-requires-manager-override') || '0') === '1',
            overrideAllowed: String($card.attr('data-override-allowed') || '0') === '1',
            overridePermission: String($card.attr('data-override-permission') || '').trim(),
            recipeEnabled: String($card.attr('data-recipe-enabled') || '0') === '1',
            recipeQty: String($card.attr('data-recipe-effective-available-qty') || '').trim()
        };
    }

    function itemUnavailableMessage(context, itemName) {
        if (context.reason) {
            return context.reason;
        }

        if (context.status === 'recipe_unavailable') {
            return 'هذا الصنف غير متاح حالياً بسبب مخزون المكونات.';
        }

        return 'هذا الصنف غير متاح حالياً.';
    }

    function showUnavailableItemMessage(context, itemName) {
        const message = itemUnavailableMessage(context, itemName);
        if (window.Swal && typeof Swal.fire === 'function') {
            Swal.fire({
                icon: 'warning',
                title: 'الصنف غير متاح',
                text: message
            });
            return;
        }

        alert(message);
    }

    function requestRecipeStockOverride(context, itemName, itemId) {
        if (!context.requiresManagerOverride) {
            return $.Deferred().resolve(null).promise();
        }

        if (!context.overrideAllowed || window.POSMAIN_CAN_RECIPE_STOCK_OVERRIDE !== true) {
            showUnavailableItemMessage(context, itemName);
            return $.Deferred().reject().promise();
        }

        const message = itemUnavailableMessage(context, itemName);
        const prompt = window.Swal && typeof Swal.fire === 'function'
            ? Swal.fire({
                icon: 'warning',
                title: 'اعتماد مدير',
                text: message,
                input: 'text',
                inputLabel: 'سبب السماح بالبيع',
                inputPlaceholder: 'اكتب السبب',
                showCancelButton: true,
                confirmButtonText: 'اعتماد وإضافة',
                cancelButtonText: 'إلغاء',
                preConfirm: function(value) {
                    return String(value || '').trim();
                }
            })
            : $.Deferred().resolve({
                isConfirmed: window.confirm(message + '\n\nاعتماد وإضافة؟'),
                value: 'recipe stock override'
            }).promise();

        return $.when(prompt).then(function(result) {
            if (!result || !result.isConfirmed) {
                return $.Deferred().reject().promise();
            }

            return $.ajax({
                url: 'ajax/manager_approval.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    action: 'approve_recipe_stock_override',
                    item_id: itemId,
                    reason: String(result.value || 'recipe stock override').trim(),
                    unavailable_reason: context.reason || message
                },
                beforeSend: window.POSMAIN_ATTACH_CSRF_HEADER
            }).then(function(response) {
                if (!response || response.success !== true || !response.approval_id) {
                    const errorMessage = response && (response.message || response.code)
                        ? (response.message || response.code)
                        : 'تعذر اعتماد المدير';
                    if (window.Swal && typeof Swal.fire === 'function') {
                        Swal.fire({ icon: 'error', title: 'تعذر الاعتماد', text: errorMessage });
                    } else {
                        alert(errorMessage);
                    }
                    return $.Deferred().reject().promise();
                }

                return response.approval_id;
            }, function(xhr) {
                const message = xhr && xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.code)
                    ? (xhr.responseJSON.message || xhr.responseJSON.code)
                    : 'تعذر اعتماد المدير';
                if (window.Swal && typeof Swal.fire === 'function') {
                    Swal.fire({ icon: 'error', title: 'تعذر الاعتماد', text: message });
                } else {
                    alert(message);
                }
                return $.Deferred().reject().promise();
            });
        });
    }

    function applyActiveItemFilter() {
        const searchText = String($filterInput.val() || '').toLowerCase().trim();
        const categoryFilter = activeCategoryFilter();

        $('.item-wrapper').each(function() {
            const $item = $(this);
            $item.toggleClass('hidden', !itemMatchesActiveFilter($item, searchText, categoryFilter));
        });
    }

    function appendLazyItems(items) {
        const existingIds = new Set();
        $('.item-card').each(function() {
            existingIds.add(String($(this).data('item-id') || ''));
        });

        const html = [];
        (items || []).forEach(function(item) {
            const itemId = String(item && item.id ? item.id : '');
            if (!itemId || existingIds.has(itemId) || !item.html) {
                return;
            }
            registerVariantCacheFromItem(item);
            existingIds.add(itemId);
            html.push(item.html);
        });

        if (html.length > 0) {
            $itemsGrid.append(html.join(''));
            applyActiveItemFilter();
        }
    }

    function loadRemainingItems() {
        if (!$itemsGrid.length || $itemsGrid.data('lazy-loading-started')) {
            return;
        }

        const pageSize = parseInt($itemsGrid.data('page-size'), 10) || 48;
        let nextPage = (parseInt($itemsGrid.data('initial-page'), 10) || 1) + 1;
        $itemsGrid.data('lazy-loading-started', true);

        function loadNextPage() {
            $itemsGridLoader.removeClass('d-none');
            $.ajax({
                url: 'ajax/load_items_lazy.php',
                method: 'GET',
                dataType: 'json',
                data: {
                    page: nextPage,
                    limit: pageSize
                },
                success: function(response) {
                    if (!response || response.success !== true) {
                        $itemsGridLoader.addClass('d-none');
                        return;
                    }

                    appendLazyItems(response.items || []);
                    if (response.has_more) {
                        nextPage += 1;
                        setTimeout(loadNextPage, 50);
                    } else {
                        $itemsGridLoader.addClass('d-none');
                    }
                },
                error: function() {
                    $itemsGridLoader.addClass('d-none');
                }
            });
        }

        setTimeout(loadNextPage, 250);
    }

    // ========================================
    // Category Filter
    // ========================================
    $('.category-btn').on('click', function() {
        const $this = $(this);
        const categoryId = $this.data('category');
        const keywords = String($this.data('keywords') || '')
            .split(',')
            .map(keyword => keyword.trim().toLowerCase())
            .filter(Boolean);

        // تحديث الأزرار
        $('.category-btn').removeClass('active btn-primary').addClass('btn-outline-primary');
        $this.removeClass('btn-outline-primary').addClass('btn-primary active');

        // مسح البحث
        $filterInput.val('');

        applyActiveItemFilter();
    });

    // ========================================
    // Barcode & Search Input
    // ========================================
    $('#barcodeInput').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            let barcode = $(this).val().trim();
            if (barcode) {
                searchItemByBarcode(barcode);
                $(this).val('');
            }
        }
    });

    $('#searchInput').on('keypress', function(e) {
        if (e.which === 13) {
            let search = $(this).val().trim();
            if (search) {
                // Try barcode search first
                searchItemByBarcode(search);

                // Also trigger the filter search
                if (search.length >= 2) {
                    $filterInput.val(search).trigger('input');
                }

                $(this).val('');
            }
        }
    });

    // البحث البسيط مع Debouncing للأداء
    let searchTimeout;
    $filterInput.on('input', function() {
        clearTimeout(searchTimeout);
        const searchText = $(this).val().toLowerCase().trim();

        // لو فاضي، اعرض الأصناف حسب التصنيف الحالي فوراً
        if (searchText === '') {
            applyActiveItemFilter();
            return;
        }

        // انتظر 200ms قبل البحث (debouncing)
        searchTimeout = setTimeout(function() {
            applyActiveItemFilter();
        }, 200);
    });

    $('#clearFilter').click(function() {
        $filterInput.val('');
        applyActiveItemFilter();
    });

    $('#focusUnifiedSearch').on('click', function() {
        $filterInput.focus().select();
    });

    $filterInput.on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            let search = $(this).val().trim();
            if (search) {
                searchItemByBarcode(search);
            }
        }
    });

    function syncModeTabs() {
        const activeId = $('input[name="age"]:checked').attr('id');
        $('.pos-mode-tab').toggleClass('active', false);
        $(`.pos-mode-tab[data-age-target="${activeId}"]`).toggleClass('active', true);
    }

    $('.pos-mode-tab').on('click', function() {
        const targetId = $(this).data('age-target');
        const $target = $('#' + targetId);
        if (!$target.length) {
            return;
        }
        $target.prop('checked', true).trigger('change');
        if (targetId === 'age2') {
            const tablesModal = document.getElementById('tablesModal');
            if (tablesModal) {
                bootstrap.Modal.getOrCreateInstance(tablesModal).show();
            }
        }
        if (targetId === 'age3' && typeof openDeliveryModal === 'function') {
            openDeliveryModal();
        }
    });
    syncModeTabs();
    syncPaymentFundOptions();
    loadRemainingItems();
    setTimeout(loadCustomerOptions, 900);

    // ========================================
    // Item Filtering Functions
    // ========================================




    // ========================================
    // Item Search & Add Functions
    // ========================================
    function searchItemByBarcode(barcode) {
        let qty = 1;
        let searchCode = barcode;

        // Check if it's a scale barcode using config
        if (posConfig && posConfig.scale_barcode && posConfig.scale_barcode.enabled) {
            const cfg = posConfig.scale_barcode;

            if (barcode.length === cfg.barcode_length &&
                barcode.substring(0, cfg.prefix.length) === cfg.prefix) {

                searchCode = barcode.substring(cfg.item_code_start,
                                               cfg.item_code_start + cfg.item_code_length);

                let weightStr = barcode.substring(cfg.weight_start,
                                                  cfg.weight_start + cfg.weight_length);
                qty = parseFloat(weightStr) / cfg.weight_divisor;
                searchCode = parseInt(searchCode).toString();

                console.log('🔢 Scale Barcode Detected:', {
                    original: barcode,
                    itemCode: searchCode,
                    weight: qty
                });
            }
        }

        $.ajax({
            url: 'ajax/search_item.php',
            type: 'POST',
            data: { barcode: searchCode },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    beginAddItemToOrder(response.item.id, response.item.name, response.item.price, response.item.barcode, qty, '', '', {
                        hasVariants: itemHasVariantsValue(response.item.has_variants)
                    });
                } else {
                    alert('الصنف غير موجود');
                }
            },
            error: function() {
                alert('خطأ في البحث عن الصنف');
            }
        });
    }

    // ========================================
    // Item Click Events
    // ========================================
    $('#itemsGrid').on('click', '.item-card.itemButton', function(e) {
        if ($(e.target).closest('.item-details-btn').length) {
            return;
        }
        e.preventDefault();
        e.stopPropagation();

        let card = $(this);
        let itemId = card.data('item-id');
        let itemName = card.data('item-name');
        let itemPrice = parseFloat(card.data('item-price')) || 0;
        let itemBarcode = card.data('item-barcode');
        let imageHtml = card.find('.item-image-container').html();
        let hasVariants = itemHasVariantsValue(card.attr('data-has-variants'));
        let availability = itemAvailabilityContext(card);
        registerVariantCacheFromCard(card);

        if (!availability.canAdd) {
            showUnavailableItemMessage(availability, itemName);
            return;
        }

        requestRecipeStockOverride(availability, itemName, itemId).then(function(managerApprovalId) {
            beginAddItemToOrder(itemId, itemName, itemPrice, itemBarcode, 1, imageHtml, '', {
                hasVariants: hasVariants,
                managerApprovalId: managerApprovalId
            });
        });
    });

    $('#itemsGrid').on('click', '.item-details-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();

        let card = $(this).closest('.item-card');
        let itemId = card.data('item-id');
        let itemName = card.data('item-name');
        let itemPrice = card.data('item-price');
        let itemBarcode = card.data('item-barcode');
        let itemDesc = card.data('item-desc') || 'لا يوجد وصف';
        let hasVariants = itemHasVariantsValue(card.attr('data-has-variants'));
        let availability = itemAvailabilityContext(card);

        let imageHtml = card.find('.item-image-container').html();
        registerVariantCacheFromCard(card);

        $('#modal_item_name').text(itemName);
        $('#modal_item_barcode').text(itemBarcode || '-');
        $('#modal_item_price').text(itemPrice.toFixed(2) + ' ج.م');
        $('#modal_item_desc').text(itemDesc);
        $('#modal_item_image').html(imageHtml);
        $('#modal_add_item')
            .prop('disabled', !availability.canAdd)
            .toggleClass('disabled', !availability.canAdd)
            .attr('title', availability.canAdd ? 'إضافة للطلب' : itemUnavailableMessage(availability, itemName));

        $('#modal_add_item').data({
            'id': itemId,
            'name': itemName,
            'price': itemPrice,
            'barcode': itemBarcode,
            'image': imageHtml,
            'hasVariants': hasVariants,
            'isAvailable': availability.isAvailable,
            'canAdd': availability.canAdd,
            'availabilityStatus': availability.status,
            'unavailableReason': availability.reason,
            'requiresManagerOverride': availability.requiresManagerOverride,
            'overrideAllowed': availability.overrideAllowed,
            'overridePermission': availability.overridePermission
        });

        $('#itemDetailsModal').modal('show');
    });

    $(document).on('click', '#modal_add_item', function() {
        let data = $(this).data();
        if (data.canAdd === false || String(data.canAdd) === 'false') {
            showUnavailableItemMessage({
                isAvailable: false,
                canAdd: false,
                status: String(data.availabilityStatus || 'manual_unavailable'),
                reason: String(data.unavailableReason || '')
            }, data.name);
            return;
        }
        let itemPrice = parseFloat(data.price) || 0;
        const availability = {
            isAvailable: data.isAvailable === true || String(data.isAvailable) === 'true',
            canAdd: true,
            status: String(data.availabilityStatus || 'available'),
            reason: String(data.unavailableReason || ''),
            requiresManagerOverride: data.requiresManagerOverride === true || String(data.requiresManagerOverride) === 'true',
            overrideAllowed: data.overrideAllowed === true || String(data.overrideAllowed) === 'true',
            overridePermission: String(data.overridePermission || '')
        };
        requestRecipeStockOverride(availability, data.name, data.id).then(function(managerApprovalId) {
            beginAddItemToOrder(data.id, data.name, itemPrice, data.barcode, 1, data.image, '', {
                hasVariants: itemHasVariantsValue(data.hasVariants),
                managerApprovalId: managerApprovalId
            });
            $('#itemDetailsModal').modal('hide');
        });
    });

    // ========================================
    // Add Item to Order
    // ========================================
    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    let activeLineNoteRow = null;
    const lineNoteDraftStoragePrefix = 'posmain.lineNotes.v1';

    function lineNoteDraftContext() {
        const orderId = String($('#edit_order_id').val() || $('#selected_order_id').val() || '').trim();
        if (orderId) {
            return 'order:' + orderId;
        }

        const tableId = String($('#selected_table_id').val() || '').trim();
        if (tableId && tableId !== '0') {
            return 'table:' + tableId;
        }

        return 'mode:' + String($('input[name="age"]:checked').val() || 'takeaway');
    }

    function lineNoteDraftStorageKey() {
        return lineNoteDraftStoragePrefix + ':' + lineNoteDraftContext();
    }

    function lineNoteItemKey(itemId, barcode) {
        return String(itemId || '') + ':' + String(barcode || '');
    }

    function lineNoteItemKeyForRow(row) {
        const $row = $(row);
        return lineNoteItemKey(
            $row.find('input[name="itmname[]"]').val(),
            $row.data('itemid') || $row.find('.barcode').val()
        );
    }

    function readLineNoteDrafts() {
        try {
            return JSON.parse(localStorage.getItem(lineNoteDraftStorageKey()) || '{}') || {};
        } catch (error) {
            return {};
        }
    }

    function getLineNoteDraft(itemId, barcode) {
        const drafts = readLineNoteDrafts();
        return String(drafts[lineNoteItemKey(itemId, barcode)] || '');
    }

    function saveLineNoteDraft(row, note) {
        try {
            const drafts = readLineNoteDrafts();
            const key = lineNoteItemKeyForRow(row);
            note = String(note || '').trim();
            if (note) {
                drafts[key] = note;
            } else {
                delete drafts[key];
            }
            localStorage.setItem(lineNoteDraftStorageKey(), JSON.stringify(drafts));
        } catch (error) {
            console.warn('Line note draft was not stored:', error);
        }
    }

    function ensureLineNoteModal() {
        if ($('#lineNoteModal').length > 0) {
            return;
        }

        $('body').append(`
            <div class="modal fade" id="lineNoteModal" tabindex="-1" aria-labelledby="lineNoteModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title" id="lineNoteModalLabel">
                                <i class="fas fa-sticky-note me-2"></i>ملاحظة للمطبخ
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <textarea class="form-control" id="lineNoteModalText" rows="4" maxlength="500" placeholder="اكتب ملاحظة المطبخ هنا"></textarea>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-danger" id="lineNoteModalClear">مسح</button>
                            <button type="button" class="btn btn-primary" id="lineNoteModalSave">حفظ</button>
                        </div>
                    </div>
                </div>
            </div>
        `);
    }

    function toggleLineNoteModal(show) {
        const modal = document.getElementById('lineNoteModal');
        if (!modal) {
            return;
        }

        if (window.bootstrap && bootstrap.Modal) {
            const instance = typeof bootstrap.Modal.getOrCreateInstance === 'function'
                ? bootstrap.Modal.getOrCreateInstance(modal)
                : new bootstrap.Modal(modal);
            show ? instance.show() : instance.hide();
            return;
        }

        $('#lineNoteModal').modal(show ? 'show' : 'hide');
    }

    function updateLineNoteButton(row) {
        const $row = $(row);
        const $input = $row.find('.lineNoteInput');
        const $button = $row.find('.lineNoteButton');
        const note = String($input.val() || '').trim();
        const hasNote = note !== '';

        $button
            .toggleClass('line-note-has-value', hasNote)
            .toggleClass('line-note-empty', !hasNote)
            .attr('title', hasNote ? 'تعديل ملاحظة المطبخ' : 'إضافة ملاحظة للمطبخ')
            .attr('aria-label', hasNote ? 'تعديل ملاحظة المطبخ' : 'إضافة ملاحظة للمطبخ');
    }

    function initializeLineNoteButtons(scope) {
        $(scope || document).find('.item-card-order').each(function() {
            const $row = $(this);
            const $input = $row.find('.lineNoteInput');
            if (String($input.val() || '').trim() === '') {
                $input.val(getLineNoteDraft(
                    $row.find('input[name="itmname[]"]').val(),
                    $row.data('itemid') || $row.find('.barcode').val()
                ));
            }
            updateLineNoteButton(this);
        });
    }

    let activeVariantContext = null;

    function fetchItemVariants(itemId) {
        return $.ajax({
            url: 'ajax/get_item_variants.php',
            type: 'GET',
            dataType: 'json',
            data: { item_id: itemId }
        }).then(function(response) {
            const variants = response && response.success && Array.isArray(response.variants) ? response.variants : [];
            cacheItemVariants(itemId, variants);
            return variants;
        }, function() {
            return [];
        });
    }

    function beginAddItemToOrder(id, name, price, barcode, qty, imageHtml, lineNote, options) {
        const hasVariantHint = options && Object.prototype.hasOwnProperty.call(options, 'hasVariants')
            ? itemHasVariantsValue(options.hasVariants)
            : null;

        if (hasVariantHint === false) {
            addItemToOrder(id, name, price, barcode, qty, imageHtml || '', lineNote || '', options || {});
            return;
        }

        const cachedVariants = cachedItemVariants(id);
        if (cachedVariants && cachedVariants.length > 0) {
            openVariantModal({
                id: id,
                name: name,
                price: parseFloat(price) || 0,
                barcode: barcode,
                qty: parseFloat(qty) || 1,
                imageHtml: imageHtml || '',
                lineNote: lineNote || '',
                managerApprovalId: options && options.managerApprovalId ? options.managerApprovalId : null,
                variants: cachedVariants
            });
            return;
        }

        fetchItemVariants(id).then(function(variants) {
            if (!variants.length) {
                addItemToOrder(id, name, price, barcode, qty, imageHtml || '', lineNote || '', options || {});
                return;
            }

            openVariantModal({
                id: id,
                name: name,
                price: parseFloat(price) || 0,
                barcode: barcode,
                qty: parseFloat(qty) || 1,
                imageHtml: imageHtml || '',
                lineNote: lineNote || '',
                managerApprovalId: options && options.managerApprovalId ? options.managerApprovalId : null,
                variants: variants
            });
        });
    }

    function ensureVariantModal() {
        if ($('#itemVariantModal').length > 0) {
            return;
        }

        $('body').append(`
            <div class="modal" id="itemVariantModal" tabindex="-1" aria-labelledby="itemVariantModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title" id="itemVariantModalLabel">
                                <i class="fas fa-list-ul me-2"></i>اختر النوع
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="fw-bold mb-3" id="itemVariantParentName"></div>
                            <div class="row g-2" id="itemVariantChoices"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">إلغاء</button>
                        </div>
                    </div>
                </div>
            </div>
        `);
    }

    function toggleVariantModal(show) {
        const modal = document.getElementById('itemVariantModal');
        if (!modal) {
            return;
        }

        if (window.bootstrap && bootstrap.Modal) {
            const instance = typeof bootstrap.Modal.getOrCreateInstance === 'function'
                ? bootstrap.Modal.getOrCreateInstance(modal)
                : new bootstrap.Modal(modal);
            show ? instance.show() : instance.hide();
            return;
        }

        $('#itemVariantModal').modal(show ? 'show' : 'hide');
    }

    function openVariantModal(context) {
        ensureVariantModal();
        activeVariantContext = context;
        $('#itemVariantParentName').text(context.name || '');
        $('#itemVariantChoices').html((context.variants || []).map(function(variant) {
            const variantItemId = parseInt(variant.item_id || variant.variant_item_id || variant.id || 0, 10) || 0;
            const variantName = String(variant.name || variant.iname || variant.variant_label || '').trim();
            const variantLabel = String(variant.variant_label || variant.label || '').trim();
            const variantBarcode = String(variant.barcode || '');
            const variantPrice = parseFloat(variant.price1 || variant.price || 0) || 0;
            const badge = variant.is_default ? '<span class="badge bg-primary ms-1">افتراضي</span>' : '';
            return `
                <div class="col-md-6">
                    <button type="button"
                            class="btn btn-outline-primary w-100 text-start itemVariantChoice"
                            data-item-id="${variantItemId}"
                            data-item-name="${escapeHtml(variantName)}"
                            data-item-price="${variantPrice}"
                            data-item-barcode="${escapeHtml(variantBarcode)}">
                        <div class="d-flex justify-content-between align-items-center gap-2">
                            <span class="fw-bold">${escapeHtml(variantLabel || variantName)} ${badge}</span>
                            <span class="text-success fw-bold">${variantPrice.toFixed(2)} ج.م</span>
                        </div>
                        <small class="text-muted d-block">${escapeHtml(variantName)}</small>
                    </button>
                </div>
            `;
        }).join(''));

        toggleVariantModal(true);
    }

    // Compatibility call form: addItemToOrder(id, name, price, barcode, qty = 1, imageHtml = '', lineNote = '')
    function addItemToOrder(id, name, price, barcode, qty = 1, imageHtml = '', lineNote = '', options = {}) {
        const managerApprovalId = parseInt(options && options.managerApprovalId ? options.managerApprovalId : 0, 10) || 0;
        const unitValue = parseFloat(options && options.uVal ? options.uVal : 1) || 1;
        let existingItem = $('.item-card-order').filter(function() {
            return String($(this).find('input[name="itmname[]"]').val()) === String(id);
        });

        if (existingItem.length > 0) {
            let qtyInput = existingItem.find('.quantityInput');
            let currentQty = parseFloat(qtyInput.val()) || 0;
            let newQty = currentQty + qty;
            qtyInput.val(newQty);

            let priceInput = existingItem.find('.priceInput');
            let itemPrice = parseFloat(priceInput.val()) || 0;
            let subtotal = newQty * itemPrice;
            existingItem.find('.subtotal').val(subtotal.toFixed(2));
            if (managerApprovalId > 0) {
                existingItem.find('.managerApprovalInput').val(managerApprovalId);
            }

            updateTotal();
            $('#barcodeInput').val('').focus();
            return;
        }

        const unitPrice = parseFloat(price) || 0;
        let subtotal = unitPrice * qty;
        let itemNumber = $('#itemData .item-card-order').length + 1;
        const thumbHtml = imageHtml
            ? `<div class="pos-cart-thumb">${imageHtml}</div>`
            : `<div class="pos-cart-thumb pos-cart-thumb-fallback"><i class="fas fa-utensils"></i></div>`;
        const noteValue = String(lineNote || '').trim() || getLineNoteDraft(id, barcode);
        const safeName = escapeHtml(name);
        const safeLineNote = escapeHtml(noteValue);

        let itemCard = `
            <div class="card mb-1 item-card-order pos-cart-row shadow-sm" data-itemid="${escapeHtml(barcode)}">
                <div class="card-body p-1">
                    <div class="pos-cart-row-inner">
                        <div class="pos-cart-value">
                            <input type="hidden" name="itmdisc[]" value="0">
                            <input type="text"
                                   class="form-control form-control-sm text-center subtotal fw-bold"
                                   readonly
                                   value="${subtotal.toFixed(2)}"
                                   name="itmval[]"
                                   title="القيمة">
                        </div>
                        <div class="pos-cart-qty">
                            <button type="button" class="btn qty-step qty-decrease" title="تقليل">−</button>
                            <input type="number"
                                   class="form-control form-control-sm text-center quantityInput nozero fw-bold"
                                   value="${qty}"
                                   name="itmqty[]"
                                   min="1"
                                   step="1"
                                   title="الكمية">
                            <button type="button" class="btn qty-step qty-increase" title="زيادة">+</button>
                            <input type="hidden" name="u_val[]" value="${escapeHtml(String(unitValue))}">
                        </div>
                        <div class="pos-cart-main">
                            <input type="hidden" value='${id}' name="itmname[]">
                            <input type="hidden" class="barcode" value="${escapeHtml(barcode)}">
                            <div class="fw-bold pos-cart-name" title="${safeName}">${safeName}</div>
                            <span class="badge bg-primary pos-cart-index">#${itemNumber}</span>
                            <input type="hidden"
                                   class="lineNoteInput"
                                   name="itmnote[]"
                                   value="${safeLineNote}">
                            <input type="hidden"
                                   class="managerApprovalInput"
                                   name="itmmanagerapproval[]"
                                   value="${managerApprovalId > 0 ? managerApprovalId : ''}">
                        </div>
                        <div class="pos-cart-note">
                            <button type="button" class="btn lineNoteButton line-note-empty" title="إضافة ملاحظة للمطبخ" aria-label="إضافة ملاحظة للمطبخ">
                                <i class="fas fa-sticky-note"></i>
                            </button>
                        </div>
                        ${thumbHtml}
                        <button type="button" class="btn btn-danger btn-sm delRow" title="حذف">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                        <div class="pos-cart-price">
                            <input type="number"
                                   class="form-control form-control-sm text-center priceInput nozero"
                                   value="${unitPrice.toFixed(2)}"
                                   name="itmprice[]"
                                   step="0.01"
                                   title="السعر">
                        </div>
                    </div>
                </div>
            </div>
        `;

        $('#itemData').append(itemCard);
        initializeLineNoteButtons($('#itemData .item-card-order').last());
        updateItemCount();
        updateTotal();
        $('#barcodeInput').val('').focus();
    }

    // ========================================
    // Update Functions
    // ========================================
    function updateItemCount() {
        let count = $('#itemData .item-card-order').length;
        $('#itemCount').text(count);
        updatePayOrderButtonState();
    }

    window.clearAllItems = function() {
        if (confirm('مسح كل الأصناف؟')) {
            $('#itemData').empty();
            $('#discount').val('0');
            $('#modal_discperc').val('0');
            $('#modal_discount').val('0');
            $('#modal_paid').val('0.00');
            $('#modal_change').val('0.00');
            updateItemCount();
            updateTotal();
        }
    };

    function updateTotal() {
        let total = 0;
        $('.subtotal').each(function() {
            total += parseFloat($(this).val()) || 0;
        });
        $('#total').val(total.toFixed(2));
        $('#total_display').text(total.toFixed(2) + ' ج.م');
        $('#total_display_btn').text(total.toFixed(2) + ' ج.م');
        $('#modal_total').text(total.toFixed(2) + ' ج.م');

        let discount = parseFloat($('#discount').val()) || 0;
        let net = total - discount;
        $('#net_val').val(net.toFixed(2));
        $('#net_display').text(net.toFixed(2) + ' ج.م');
        $('#modal_net').text(net.toFixed(2) + ' ج.م');

        setDefaultCashPaymentToNet(net);
        updatePayOrderButtonState();
    }

    function setDefaultCashPaymentToNet(netAmount) {
        if ($('#pos_split_payment_enabled').prop('checked')) {
            refreshSplitPaymentLineAmounts();
            return;
        }

        const net = Math.max(0, parseFloat(netAmount) || 0);
        $('#modal_paid_cash').val(net.toFixed(2));
        $('#modal_paid_bank').val('0.00');
        calculateChange();
    }

    // ========================================
    // Tables System
    // ========================================

    let tablesRefreshTimer = null;
    let tablesRefreshInFlight = false;
    let tablesPreloadStarted = false;
    let tableTransferMode = false;
    let tableTransferInFlight = false;
    let latestTablesState = [];
    const defaultTablesModalTitle = $('#tablesModalLabel').html() || '<i class="fas fa-th-large me-2"></i>اختر الطاولة';

    function escapeHtml(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function getSelectedTableId() {
        return parseInt($('#selected_table_id').val() || 0, 10) || 0;
    }

    function getActiveTableOrderId() {
        return String($('#selected_order_id').val() || $('#edit_order_id').val() || '').trim();
    }

    function isHeldTableWithoutActiveOrder() {
        return getSelectedTableId() > 0
            && String($('#selected_table_case').val() || '0') === '1'
            && getActiveTableOrderId() === ''
            && $('#itemData .item-card-order').length === 0;
    }
    window.POSMainIsHeldTableWithoutActiveOrder = isHeldTableWithoutActiveOrder;

    function updatePayOrderButtonState() {
        const $button = $('.pos-pay-order-btn');
        if (!$button.length) {
            return;
        }

        const shouldFreeOnly = isHeldTableWithoutActiveOrder();
        const $label = $button.find('span');
        const $amount = $('#total_display_btn');
        if (shouldFreeOnly) {
            $button.removeAttr('data-bs-toggle data-bs-target');
            $label.html('<i class="fas fa-chair me-1"></i>أفرغ الطاولة');
            $amount.hide();
            return;
        }

        $button.attr('data-bs-toggle', 'modal');
        $button.attr('data-bs-target', '#paymentModal');
        $label.html('<i class="fas fa-money-bill-wave me-1"></i>دفع');
        $amount.show();
    }

    function currentOrderDiscountRate() {
        const total = Math.max(0, parseFloat($('#total').val()) || 0);
        const discount = Math.min(total, Math.max(0, parseFloat($('#discount').val()) || 0));
        return total > 0 ? discount / total : 0;
    }

    function updateTransferTableButton() {
        const hasActiveTableOrder = getSelectedTableId() > 0 && getActiveTableOrderId() !== '';
        $('#transferTableBtn').toggle(hasActiveTableOrder);
    }

    function selectedSplitPaymentRows() {
        const rows = [];
        const discountRate = currentOrderDiscountRate();
        $('#itemData .item-card-order').each(function(index) {
            const $row = $(this);
            const qty = parseFloat($row.find('.quantityInput').val()) || 0;
            const subtotal = parseFloat($row.find('.subtotal').val()) || 0;
            const discountedAmount = Math.max(0, subtotal * (1 - discountRate));
            const unitAmount = qty > 0 ? discountedAmount / qty : 0;
            rows.push({
                row_index: index,
                name: String($row.find('.pos-cart-name').text() || '').trim() || 'صنف',
                qty: qty,
                unit_amount: unitAmount,
                amount: discountedAmount
            });
        });

        return rows;
    }

    function renderSplitPaymentRows() {
        const $body = $('#pos_split_payment_rows');
        if (!$body.length) {
            return;
        }

        const rows = selectedSplitPaymentRows();
        if (!rows.length) {
            $body.html('<tr><td colspan="4" class="text-center text-muted">لا توجد أصناف في الطلب</td></tr>');
            updateSplitPaymentTotal();
            return;
        }

        const html = rows.map(function(row) {
            const qty = Number(row.qty || 0);
            const amount = Number(row.amount || 0);
            return `
                <tr data-row-index="${row.row_index}" data-unit-amount="${row.unit_amount}">
                    <td>
                        <input type="checkbox" class="form-check-input pos-split-line-check">
                    </td>
                    <td class="fw-bold">${escapeHtml(row.name)}</td>
                    <td>
                        <input type="number"
                               class="form-control form-control-sm text-center pos-split-line-qty"
                               value="${qty}"
                               min="0"
                               max="${qty}"
                               step="1"
                               data-max-qty="${qty}">
                    </td>
                    <td class="text-end">
                        <span class="pos-split-line-total">${amount.toFixed(2)}</span>
                    </td>
                </tr>
            `;
        }).join('');

        $body.html(html);
        updateSplitPaymentTotal();
    }

    function refreshSplitPaymentLineAmounts() {
        const rowsByIndex = {};
        selectedSplitPaymentRows().forEach(function(row) {
            rowsByIndex[row.row_index] = row;
        });

        $('#pos_split_payment_rows tr').each(function() {
            const $row = $(this);
            const rowIndex = parseInt($row.data('row-index'), 10);
            const sourceRow = rowsByIndex[rowIndex];
            if (!sourceRow) {
                return;
            }

            $row.data('unit-amount', sourceRow.unit_amount);
            $row.attr('data-unit-amount', sourceRow.unit_amount);
        });

        updateSplitPaymentTotal();
    }

    function updateSplitPaymentTotal() {
        let selectedTotal = 0;
        $('#pos_split_payment_rows tr').each(function() {
            const $row = $(this);
            const checked = $row.find('.pos-split-line-check').prop('checked');
            const maxQty = parseFloat($row.find('.pos-split-line-qty').data('max-qty')) || 0;
            let qty = parseFloat($row.find('.pos-split-line-qty').val()) || 0;
            qty = Math.max(0, Math.min(qty, maxQty));
            $row.find('.pos-split-line-qty').val(qty || '');
            const unitAmount = parseFloat($row.data('unit-amount')) || 0;
            const lineTotal = qty * unitAmount;
            $row.find('.pos-split-line-total').text(lineTotal.toFixed(2));
            if (checked) {
                selectedTotal += lineTotal;
            }
        });

        $('#pos_split_payment_total').text(selectedTotal.toFixed(2) + ' ج.م');
        if ($('#pos_split_payment_enabled').prop('checked')) {
            $('#modal_paid_cash').val(selectedTotal.toFixed(2));
            $('#modal_paid_bank').val('0.00');
            calculateChange();
        }
    }

    function updateSplitPaymentButtons() {
        const splitEnabled = $('#pos_split_payment_enabled').prop('checked');
        $('.pos-pay-confirm-btn').toggle(!splitEnabled);
        $('.pos-split-pay-confirm-btn').toggle(splitEnabled);
    }

    function splitPaymentPayloadFromModal() {
        const rows = [];
        let selectedTotal = 0;
        $('#pos_split_payment_rows tr').each(function() {
            const $row = $(this);
            if (!$row.find('.pos-split-line-check').prop('checked')) {
                return;
            }
            const rowIndex = parseInt($row.data('row-index'), 10);
            const maxQty = parseFloat($row.find('.pos-split-line-qty').data('max-qty')) || 0;
            const qty = parseFloat($row.find('.pos-split-line-qty').val()) || 0;
            const unitAmount = parseFloat($row.data('unit-amount')) || 0;
            if (Number.isNaN(rowIndex) || qty <= 0 || qty > maxQty + 0.0001) {
                return;
            }
            rows.push({ row_index: rowIndex, qty: qty });
            selectedTotal += qty * unitAmount;
        });

        return { rows: rows, total: selectedTotal };
    }

    function ensureHiddenFormInput(form, name) {
        let input = form.querySelector(`input[name="${name}"]`);
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            form.appendChild(input);
        }

        return input;
    }

    window.POSMainPrepareSplitPaymentFields = function(form, paymentState) {
        if (!$('#pos_split_payment_enabled').prop('checked')) {
            $('#pos_split_payment_enabled').prop('checked', true);
            $('#pos_split_payment_panel').show();
        }

        if (getSelectedTableId() <= 0) {
            alert('سداد أصناف محددة متاح لطلبات الطاولات فقط');
            return false;
        }

        const payload = splitPaymentPayloadFromModal();
        if (!payload.rows.length || payload.total <= 0) {
            alert('اختر صنف واحد على الأقل وكمية صحيحة للسداد');
            return false;
        }

        const paidCash = parseFloat(paymentState.paidCash) || 0;
        const paidBank = parseFloat(paymentState.paidBank) || 0;
        if (paidCash > 0 && paidBank > 0) {
            alert('سداد الأصناف المحددة يستخدم طريقة دفع واحدة في كل مرة');
            return false;
        }

        const totalPaid = paidCash + paidBank;
        if (Math.abs(totalPaid - payload.total) > 0.01) {
            alert('مبلغ الدفع يجب أن يساوي إجمالي الأصناف المحددة');
            return false;
        }

        ensureHiddenFormInput(form, 'pos_split_payment_payload').value = JSON.stringify(payload.rows);
        ensureHiddenFormInput(form, 'pos_split_payment_total').value = payload.total.toFixed(2);
        ensureHiddenFormInput(form, 'pos_split_payment_method').value = paidBank > 0 ? 'bank' : 'cash';
        return true;
    };

    function showPOSNotice(message, type) {
        type = type || 'success';
        if (window.Swal && typeof window.Swal.fire === 'function') {
            Swal.fire({
                icon: type === 'danger' ? 'error' : type,
                title: message,
                timer: 1600,
                showConfirmButton: false
            });
            return;
        }

        const alertClass = type === 'danger' ? 'alert-danger' : 'alert-success';
        const alertDiv = $(`<div class="alert ${alertClass} position-fixed top-0 start-50 translate-middle-x mt-3" style="z-index: 9999;">${escapeHtml(message)}</div>`);
        $('body').append(alertDiv);
        setTimeout(() => alertDiv.fadeOut(() => alertDiv.remove()), 2000);
    }

    function confirmPOSAction(title, text, confirmButtonText) {
        if (window.Swal && typeof window.Swal.fire === 'function') {
            return Swal.fire({
                icon: 'question',
                title: title,
                text: text,
                showCancelButton: true,
                confirmButtonText: confirmButtonText,
                cancelButtonText: 'إلغاء',
                reverseButtons: true
            }).then(result => !!result.isConfirmed);
        }

        return Promise.resolve(confirm(text || title));
    }

    function setTableTransferMode(enabled) {
        tableTransferMode = !!enabled;
        $('#tableTransferHint').toggleClass('d-none', !tableTransferMode);
        $('#tablesModalLabel').html(tableTransferMode
            ? '<i class="fas fa-exchange-alt me-2"></i>نقل الطاولة'
            : defaultTablesModalTitle
        );

        if ($('#tablesGrid').length && latestTablesState.length > 0) {
            renderTablesGrid(latestTablesState);
        }
    }

    window.openTableTransferFlow = function() {
        const sourceTableId = getSelectedTableId();
        const sourceOrderId = getActiveTableOrderId();

        if (!sourceTableId || !sourceOrderId) {
            showPOSNotice('اختر طاولة عليها طلب محفوظ أولاً', 'danger');
            return;
        }

        setTableTransferMode(true);
        bootstrap.Modal.getOrCreateInstance(document.getElementById('tablesModal')).show();
        window.refreshTablesState();
    };

    function formatTableAmount(value) {
        const amount = parseFloat(value) || 0;
        return amount.toFixed(2);
    }

    function renderTableButton(table) {
        const tableId = parseInt(table.id || table.table_id || 0, 10);
        const tableName = String(table.tname || table.table_name || '');
        const tableCase = parseInt(table.table_case || table.has_active_order || 0, 10) ? 1 : 0;
        const orderId = table.order_id ? parseInt(table.order_id, 10) : '';
        const orderTotal = parseFloat(table.fat_net || 0) || 0;
        let statusClass = tableCase ? 'btn-danger' : 'btn-success';
        let statusIcon = tableCase ? 'fa-utensils' : 'fa-check-circle';
        let statusText = tableCase ? 'مشغولة' : 'متاحة';
        let transferAction = '';
        let transferClass = '';
        let disabledAttr = '';

        if (tableTransferMode) {
            if (tableId === getSelectedTableId()) {
                statusClass = 'btn-secondary';
                statusIcon = 'fa-map-marker-alt';
                statusText = 'الطاولة الحالية';
                transferClass = 'pos-transfer-source';
                disabledAttr = 'disabled';
            } else if (tableCase) {
                statusClass = 'btn-outline-success';
                statusIcon = 'fa-compress-arrows-alt';
                statusText = 'مشغولة - دمج هنا';
                transferAction = 'merge';
                transferClass = 'pos-transfer-target pos-transfer-target-merge';
            } else {
                statusClass = 'btn-outline-primary';
                statusIcon = 'fa-exchange-alt';
                statusText = 'متاحة - انقل هنا';
                transferAction = 'move';
                transferClass = 'pos-transfer-target pos-transfer-target-move';
            }
        }

        const totalBadge = tableCase && orderTotal > 0
            ? `<div class="mt-2 badge bg-white text-dark">${formatTableAmount(orderTotal)} ج.م</div>`
            : '';

        return `
            <div class="col-md-4 col-sm-6">
                <button type="button"
                    class="btn ${statusClass} w-100 table-select-btn position-relative ${transferClass}"
                    data-table-id="${tableId}"
                    data-table-name="${escapeHtml(tableName)}"
                    data-table-case="${tableCase}"
                    data-order-id="${orderId}"
                    data-has-active-order="${tableCase}"
                    data-transfer-action="${transferAction}"
                    data-destination-order-id="${orderId}"
                    ${disabledAttr}
                    style="min-height: 120px; font-size: 1.1rem;">
                    <div class="d-flex flex-column align-items-center justify-content-center">
                        <i class="fas ${statusIcon} fa-2x mb-2"></i>
                        <h6 class="mb-1">${escapeHtml(tableName)}</h6>
                        <small class="d-flex align-items-center">
                            <i class="fas ${statusIcon} me-1"></i>
                            ${statusText}
                        </small>
                        ${totalBadge}
                    </div>
                </button>
            </div>
        `;
    }

    function renderTablesGrid(tables) {
        const $grid = $('#tablesGrid');
        if (!$grid.length) {
            return;
        }

        if (!Array.isArray(tables) || tables.length === 0) {
            latestTablesState = [];
            $grid.html(`
                <div class="col-12 text-center text-muted">
                    <i class="fas fa-exclamation-circle fa-3x mb-3"></i>
                    <p>لا توجد طاولات متاحة</p>
                </div>
            `);
            return;
        }

        latestTablesState = tables;
        $grid.html(tables.map(renderTableButton).join(''));
    }

    function renderTablesLoadError() {
        const $grid = $('#tablesGrid');
        if (!$grid.length) {
            return;
        }

        if (Array.isArray(latestTablesState) && latestTablesState.length > 0) {
            renderTablesGrid(latestTablesState);
            return;
        }

        $grid.html(`
            <div class="col-12 text-center text-muted">
                <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                <p>تعذر تحميل الطاولات من الخادم المحلي</p>
            </div>
        `);
    }

    window.refreshTablesState = function() {
        if (tablesRefreshInFlight || !$('#tablesGrid').length) {
            return;
        }

        tablesRefreshInFlight = true;
        $.ajax({
            url: 'ajax/get_tables.php',
            method: 'GET',
            dataType: 'json',
            cache: false,
            success: function(response) {
                if (response && response.success) {
                    renderTablesGrid(response.tables || []);
                }
            },
            error: function() {
                renderTablesLoadError();
            },
            complete: function() {
                tablesRefreshInFlight = false;
            }
        });
    };

    function startTablesAutoRefresh() {
        if (tablesPreloadStarted || !$('#tablesGrid').length) {
            return;
        }

        tablesPreloadStarted = true;
        setTimeout(function() {
            if (typeof window.refreshTablesState === 'function') {
                window.refreshTablesState();
            }
        }, 800);
        tablesRefreshTimer = setInterval(function() {
            if (typeof window.refreshTablesState === 'function') {
                window.refreshTablesState();
            }
        }, 5000);
    }

    $('#tablesModal').on('shown.bs.modal', function() {
        window.refreshTablesState();
    });

    $('#tablesModal').on('hidden.bs.modal', function() {
        setTableTransferMode(false);
    });

    startTablesAutoRefresh();

    // مسح الطاولة عند التبديل لتيك أواي أو دليفري
    $('input[name="age"]').on('change', function() {
        syncModeTabs();
        const val = $(this).val();
        if (val == '2') {
            setTableDefaultCustomer();
        } else if (val == '1' || val == '3') {
            // تيك أواي أو دليفري - امسح الطاولة المختارة
            $('#selected_table_id').val('');
            $('#selected_table_name').val('');
            $('#selected_table_case').val('0');
            $('#selected_order_id').val('');
            $('#edit_order_id').val('');
            $('#selected_table_display').html('اختر طاولة');
            restoreInitialCustomer();
            updateTransferTableButton();
            updatePayOrderButtonState();
        }
    });

    $(document).on('click', '.table-select-btn', function() {
        if (tableTransferMode) {
            handleTableTransferDestination($(this));
            return;
        }

        const tableId = $(this).data('table-id');
        const tableName = $(this).data('table-name');
        const tableCase = $(this).data('table-case');
        const orderId = $(this).data('order-id');

        $('#selected_table_id').val(tableId);
        $('#selected_table_name').val(tableName);
        $('#selected_table_case').val(tableCase ? '1' : '0');
        $('#selected_table_display').html('<i class="fas fa-chair me-1"></i>' + tableName);
        $('#age2').prop('checked', true);
        syncModeTabs();
        setTableDefaultCustomer();
        $('#tablesModal').modal('hide');

        if (tableCase != 0 && orderId) {
            // طاولة فيها طلب - حمل الطلب واضيف عليه
            $('#selected_order_id').val(orderId);
            updateTransferTableButton();
            loadExistingOrder(orderId, tableName);
        } else {
            // طاولة فاضية - طلب جديد
            $('#selected_order_id').val('');
            $('#edit_order_id').val('');
            $('#itemData').empty();
            updateItemCount();
            updateTotal();
            updateTransferTableButton();
            updatePayOrderButtonState();
            console.log('طاولة فاضية: ' + tableName + ' - طلب جديد');
        }
    });

    window.selectNoTable = function() {
        $('#selected_table_id').val('');
        $('#selected_table_name').val('');
        $('#selected_table_case').val('0');
        $('#selected_order_id').val('');
        $('#edit_order_id').val('');
        $('#selected_table_display').html('بدون طاولة');
        $('#age1').prop('checked', true);
        syncModeTabs();
        restoreInitialCustomer();
        $('#tablesModal').modal('hide');
        clearAllItems();
        updateTransferTableButton();
        updatePayOrderButtonState();
    };

    function handleTableTransferDestination($button) {
        if (tableTransferInFlight || $button.prop('disabled')) {
            return;
        }

        const sourceTableId = getSelectedTableId();
        const sourceOrderId = getActiveTableOrderId();
        const destinationTableId = parseInt($button.data('table-id') || 0, 10) || 0;
        const destinationName = String($button.data('table-name') || '');
        const destinationOrderId = $button.data('destination-order-id') || $button.data('order-id') || '';
        const transferAction = String($button.data('transfer-action') || '');

        if (!sourceTableId || !sourceOrderId) {
            showPOSNotice('لا يوجد طلب نشط لنقله', 'danger');
            return;
        }

        if (!destinationTableId || destinationTableId === sourceTableId || !transferAction) {
            showPOSNotice('اختر طاولة أخرى', 'danger');
            return;
        }

        const isMerge = transferAction === 'merge';
        const title = isMerge ? 'دمج الطلبين؟' : 'نقل الطلب؟';
        const text = isMerge
            ? 'سيتم نقل أصناف الطلب المحفوظ إلى ' + destinationName + ' وإفراغ الطاولة الحالية. احفظ أي تعديل قبل الدمج.'
            : 'سيتم نقل الطلب المحفوظ بالكامل إلى ' + destinationName + '. احفظ أي تعديل قبل النقل.';
        const confirmText = isMerge ? 'دمج الطلبين' : 'نقل الطلب';

        confirmPOSAction(title, text, confirmText).then(function(confirmed) {
            if (!confirmed) {
                return;
            }

            performTableTransfer(transferAction, {
                sourceTableId: sourceTableId,
                sourceOrderId: sourceOrderId,
                destinationTableId: destinationTableId,
                destinationTableName: destinationName,
                destinationOrderId: destinationOrderId
            });
        });
    }

    function performTableTransfer(transferAction, transferData) {
        tableTransferInFlight = true;
        const isMerge = transferAction === 'merge';
        const requestScope = isMerge ? 'pos.table.merge' : 'pos.table.move';
        const ajaxData = {
            source_table_id: transferData.sourceTableId,
            destination_table_id: transferData.destinationTableId,
            idempotency_key: createPOSIdempotencyKey(requestScope)
        };

        if (isMerge) {
            ajaxData.source_order_id = transferData.sourceOrderId;
            ajaxData.destination_order_id = transferData.destinationOrderId;
        } else {
            ajaxData.order_id = transferData.sourceOrderId;
        }

        $('.pos-transfer-target').prop('disabled', true).addClass('disabled');

        $.ajax({
            url: isMerge ? 'ajax/merge_table_orders.php' : 'ajax/move_table_order.php',
            method: 'POST',
            dataType: 'json',
            data: ajaxData,
            beforeSend: window.POSMAIN_ATTACH_CSRF_HEADER,
            success: function(response) {
                if (!response || !response.success) {
                    showPOSNotice((response && response.message) || 'فشل نقل الطاولة', 'danger');
                    return;
                }

                const nextOrderId = isMerge
                    ? (response.destination_order_id || transferData.destinationOrderId)
                    : (response.order_id || transferData.sourceOrderId);

                    $('#selected_table_id').val(transferData.destinationTableId);
                    $('#selected_table_name').val(transferData.destinationTableName);
                    $('#selected_table_case').val('1');
                    $('#selected_order_id').val(nextOrderId);
                    $('#edit_order_id').val(nextOrderId);
                $('#selected_table_display').html('<i class="fas fa-chair me-1"></i>' + escapeHtml(transferData.destinationTableName));
                $('#age2').prop('checked', true);
                syncModeTabs();
                setTableDefaultCustomer();
                updateTransferTableButton();
                $('#tablesModal').modal('hide');
                loadExistingOrder(nextOrderId, transferData.destinationTableName, { silent: true });
                window.refreshTablesState();
                showPOSNotice(isMerge ? 'تم دمج الطلب في الطاولة الجديدة' : 'تم نقل الطلب إلى الطاولة الجديدة', 'success');
            },
            error: function(xhr, status, error) {
                console.error('Table transfer error:', xhr.responseText || error);
                showPOSNotice('حدث خطأ أثناء نقل الطاولة', 'danger');
            },
            complete: function() {
                tableTransferInFlight = false;
                $('.pos-transfer-target').prop('disabled', false).removeClass('disabled');
            }
        });
    }

    function loadExistingOrder(orderId, tableName, options) {
        options = options || {};
        console.log('🔄 Loading existing order:', orderId, 'Table:', tableName);

        $.ajax({
            url: 'ajax/load_order.php',
            method: 'POST',
            data: { order_id: orderId, table_id: $('#selected_table_id').val() },
            dataType: 'json',
            success: function(response) {
                console.log('📥 Load Order Response:', response);

                if (response.success) {
                    $('#itemData').empty();

                    if (response.items && response.items.length > 0) {
                        console.log('📦 Found items:', response.items.length);
                        response.items.forEach(function(item) {
                            console.log('➕ Adding item:', item);
                            addItemToOrder(
                                item.item_id,
                                item.item_name || 'Unknown Item',
                                parseFloat(item.price) || 0,
                                item.barcode || item.item_desc || item.item_id, // Use explicit barcode first
                                parseFloat(item.qty) || 1,
                                '',
                                item.note || item.kitchen_note || item.notes || '',
                                { uVal: item.u_val || 1 }
                            );
                        });
                    } else {
                        console.warn('⚠️ No items found in order');
                    }

                    if (response.order) {
                        $('#discount').val(response.order.discount || 0);
                        if (response.order.emp_id) $('select[name="emp_id"]').val(response.order.emp_id);
                        if (customerOptionExists(response.order.acc1)) {
                            selectCustomerById(response.order.acc1);
                        } else {
                            selectCustomerById(getTableDefaultCustomerId());
                        }
                        // Set hidden edit_order_id
                        $('#edit_order_id').val(response.order.id);
                        $('#selected_order_id').val(response.order.id);
                    }

                    updateItemCount();
                    updateTotal();
                    updateTransferTableButton();

                    // Show success message briefly
                    if (!options.silent) {
                        const alertDiv = $('<div class="alert alert-success position-fixed top-0 start-50 translate-middle-x mt-3" style="z-index: 9999;">تم تحميل الطلب بنجاح</div>');
                        $('body').append(alertDiv);
                        setTimeout(() => alertDiv.fadeOut(() => alertDiv.remove()), 2000);
                    }

                } else {
                    console.error('❌ Load failed:', response.error);
                    alert('خطأ في تحميل طلب الطاولة: ' + (response.error || 'غير معروف'));
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ AJAX Error:', error);
                console.error('Response:', xhr.responseText);
                alert('خطأ في الاتصال بالخادم');
            }
        });
    }

    updateTransferTableButton();

    // ========================================
    // Modal Calculations
    // ========================================
    $('#modal_discperc').on('input', function() {
        let total = parseFloat($('#total').val()) || 0;
        let discount = (total * (parseFloat($(this).val()) || 0) / 100).toFixed(2);
        $('#modal_discount').val(discount);
        $('#discount').val(discount);
        let net = (total - discount).toFixed(2);
        $('#modal_net').text(net + ' ج.م');
        $('#net_val').val(net);
        $('#net_display').text(net + ' ج.م');

        setDefaultCashPaymentToNet(net);
    });

    $('#modal_discount').on('input', function() {
        let total = parseFloat($('#total').val()) || 0;
        let discount = parseFloat($(this).val()) || 0;
        $('#discount').val(discount);
        let percentage = total > 0 ? ((discount / total) * 100).toFixed(2) : 0;
        $('#modal_discperc').val(percentage);
        let net = (total - discount).toFixed(2);
        $('#modal_net').text(net + ' ج.م');
        $('#net_val').val(net);
        $('#net_display').text(net + ' ج.م');

        setDefaultCashPaymentToNet(net);
    });

    // حساب الباقي عند تغيير المدفوع كاش أو صرافة
    $('#modal_paid_cash, #modal_paid_bank').on('input', function() {
        calculateChange();
    });

    $('#paymentModal').on('shown.bs.modal', function() {
        syncPaymentFundOptions();
        loadBankOptions();
        const isTableOrder = getSelectedTableId() > 0 && $('#age2').prop('checked');
        $('.pos-empty-table-option').toggle(isTableOrder);
        $('#pos_empty_table_after_payment').prop('checked', true);
        renderSplitPaymentRows();
    });

    $(document).on('change', '#pos_split_payment_enabled', function() {
        const enabled = $(this).prop('checked');
        $('#pos_split_payment_panel').toggle(enabled);
        updateSplitPaymentButtons();
        if (enabled) {
            renderSplitPaymentRows();
        } else {
            $('#pos_split_payment_total').text('0.00 ج.م');
            updateTotal();
        }
    });

    $(document).on('change input', '.pos-split-line-check, .pos-split-line-qty', updateSplitPaymentTotal);

    $(document).on('click', '.pos-pay-order-btn', function(event) {
        if (!isHeldTableWithoutActiveOrder()) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        submitPOS('free_table');
    });

    function calculateChange() {
        let net = parseFloat($('#net_val').val()) || 0;
        let paidCash = parseFloat($('#modal_paid_cash').val()) || 0;
        let paidBank = parseFloat($('#modal_paid_bank').val()) || 0;
        let totalPaid = paidCash + paidBank;

        let change = totalPaid - net;

        // الباقي للحساب فقط - لا يؤثر على السند
        $('#modal_change').text(change.toFixed(2) + ' ج.م');
    }

    // ========================================
    // Delete & Update Row
    // ========================================
    $(document).on('click', '.delRow', function() {
        $(this).closest('.item-card-order').remove();
        updateItemCount();
        updateTotal();
    });

    $(document).on('click', '.qty-increase, .qty-decrease', function() {
        let card = $(this).closest('.item-card-order');
        let qtyInput = card.find('.quantityInput');
        let currentQty = parseFloat(qtyInput.val()) || 0;
        let nextQty = $(this).hasClass('qty-increase') ? currentQty + 1 : currentQty - 1;
        qtyInput.val(Math.max(1, Math.round(nextQty))).trigger('input');
    });

    $(document).on('input', '.quantityInput, .priceInput', function() {
        let card = $(this).closest('.item-card-order');
        let qty = parseFloat(card.find('.quantityInput').val()) || 0;
        let price = parseFloat(card.find('.priceInput').val()) || 0;
        let subtotal = qty * price;
        card.find('.subtotal').val(subtotal.toFixed(2));
        updateTotal();
    });

    $(document).on('click', '.itemVariantChoice', function() {
        if (!activeVariantContext) {
            return;
        }

        const $choice = $(this);
        addItemToOrder(
            parseInt($choice.data('item-id'), 10) || 0,
            String($choice.data('item-name') || ''),
            parseFloat($choice.data('item-price')) || 0,
            String($choice.data('item-barcode') || ''),
            activeVariantContext.qty || 1,
            activeVariantContext.imageHtml || '',
            activeVariantContext.lineNote || '',
            {
                managerApprovalId: activeVariantContext.managerApprovalId || null
            }
        );
        toggleVariantModal(false);
    });

    $(document).on('hidden.bs.modal', '#itemVariantModal', function() {
        activeVariantContext = null;
    });

    $(document).on('click', '.lineNoteButton', function() {
        ensureLineNoteModal();
        activeLineNoteRow = $(this).closest('.item-card-order');
        $('#lineNoteModalText').val(activeLineNoteRow.find('.lineNoteInput').val() || '');
        toggleLineNoteModal(true);
    });

    $(document).on('click', '#lineNoteModalSave', function() {
        if (!activeLineNoteRow || activeLineNoteRow.length === 0) {
            toggleLineNoteModal(false);
            return;
        }

        const note = $('#lineNoteModalText').val() || '';
        activeLineNoteRow.find('.lineNoteInput').val(note);
        saveLineNoteDraft(activeLineNoteRow, note);
        updateLineNoteButton(activeLineNoteRow);
        toggleLineNoteModal(false);
    });

    $(document).on('click', '#lineNoteModalClear', function() {
        $('#lineNoteModalText').val('');
        if (activeLineNoteRow && activeLineNoteRow.length > 0) {
            activeLineNoteRow.find('.lineNoteInput').val('');
            saveLineNoteDraft(activeLineNoteRow, '');
            updateLineNoteButton(activeLineNoteRow);
        }
    });

    $(document).on('hidden.bs.modal', '#lineNoteModal', function() {
        activeLineNoteRow = null;
    });

    initializeLineNoteButtons();

    // ========================================
    // Form Submission
    // ========================================
    function createPOSIdempotencyKey(scope) {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return scope + ':' + window.crypto.randomUUID();
        }

        return scope + ':' + Date.now().toString(36) + ':' + Math.random().toString(36).slice(2);
    }

    function ensureFormIdempotencyKey(form, action) {
        const scope = action === 'save' ? 'pos.order.save' : (action === 'free_table' ? 'pos.table.free' : 'pos.order.pay');
        let keyInput = form.querySelector('input[name="idempotency_key"]');
        if (!keyInput) {
            keyInput = document.createElement('input');
            keyInput.type = 'hidden';
            keyInput.name = 'idempotency_key';
            form.appendChild(keyInput);
        }

        if (!keyInput.value || keyInput.dataset.action !== action) {
            keyInput.value = createPOSIdempotencyKey(scope);
            keyInput.dataset.action = action;
        }

        return keyInput.value;
    }

    window.submitPOS = function(action) {
        console.log('✅ submitPOS called with action:', action);

        const form = document.getElementById('posForm');
        if (!form) {
            console.error('❌ Form with id "posForm" not found!');
            alert('حدث خطأ في النظام. يرجى إعادة تحميل الصفحة.');
            return false;
        }

        const isFreeTableOnly = action === 'free_table';
        if (isFreeTableOnly && !isHeldTableWithoutActiveOrder()) {
            alert('إفراغ الطاولة متاح فقط لطاولة مشغولة بدون طلب مفتوح');
            return false;
        }

        console.log('🔍 Validating form...');
        if (!isFreeTableOnly && !validatePOSForm()) {
            console.log('❌ Validation failed, form not submitted');
            return false;
        }
        console.log('✅ Validation passed');

        if (action === 'cash' && $('#pos_split_payment_enabled').prop('checked')) {
            action = 'split_cash';
        }

        // جمع بيانات الدفع
        const isSaveOnly = action === 'save';
        const isSplitLinePayment = action === 'split_cash';
        let paidCash = parseFloat($('#modal_paid_cash').val()) || 0;
        let paidBank = parseFloat($('#modal_paid_bank').val()) || 0;
        if (isSaveOnly || isFreeTableOnly) {
            paidCash = 0;
            paidBank = 0;
        }
        syncPaymentFundOptions();
        let fundId = $('#payment_fund_id').val();
        let bankId = $('#payment_bank_id').val();
        let net = parseFloat($('#net_val').val()) || 0;

        console.log('=== PAYMENT DATA DEBUG ===');
        console.log('modal_paid_cash value:', $('#modal_paid_cash').val());
        console.log('modal_paid_bank value:', $('#modal_paid_bank').val());
        console.log('payment_fund_id value:', $('#payment_fund_id').val());
        console.log('payment_bank_id value:', $('#payment_bank_id').val());
        console.log('Processed:', {
            paidCash: paidCash,
            paidBank: paidBank,
            fundId: fundId,
            bankId: bankId,
            net: net
        });
        console.log('==========================');

        // التحقق من صحة البيانات
        if (!isSaveOnly && !isFreeTableOnly && !isSplitLinePayment && net > 0 && paidCash + paidBank <= 0) {
            alert('يجب إدخال مبلغ الدفع قبل تأكيد الدفع');
            return false;
        }

        if (!isSaveOnly && !isFreeTableOnly && paidCash > 0 && (!fundId || fundId == '0')) {
            alert('يجب اختيار الصندوق عند الدفع كاش');
            return false;
        }

        if (!isSaveOnly && !isFreeTableOnly && paidBank > 0 && (!bankId || bankId == '0' || bankId == '')) {
            alert('يجب اختيار البنك عند الدفع صرافة');
            return false;
        }

        if (isSplitLinePayment && typeof window.POSMainPrepareSplitPaymentFields === 'function') {
            if (!window.POSMainPrepareSplitPaymentFields(form, {
                paidCash: paidCash,
                paidBank: paidBank,
                fundId: fundId,
                bankId: bankId
            })) {
                return false;
            }
            paidCash = parseFloat($('#modal_paid_cash').val()) || 0;
            paidBank = parseFloat($('#modal_paid_bank').val()) || 0;
        }

        // إضافة حقول الدفع المخفية
        let paidCashInput = form.querySelector('input[name="paid_cash"]');
        if (!paidCashInput) {
            paidCashInput = document.createElement('input');
            paidCashInput.type = 'hidden';
            paidCashInput.name = 'paid_cash';
            form.appendChild(paidCashInput);
            console.log('✅ Created paid_cash input');
        }
        paidCashInput.value = paidCash;
        console.log('Set paid_cash =', paidCash);

        let paidBankInput = form.querySelector('input[name="paid_bank"]');
        if (!paidBankInput) {
            paidBankInput = document.createElement('input');
            paidBankInput.type = 'hidden';
            paidBankInput.name = 'paid_bank';
            form.appendChild(paidBankInput);
            console.log('✅ Created paid_bank input');
        }
        paidBankInput.value = paidBank;
        console.log('Set paid_bank =', paidBank);

        let paymentFundInput = form.querySelector('input[name="payment_fund_id"]');
        if (!paymentFundInput) {
            paymentFundInput = document.createElement('input');
            paymentFundInput.type = 'hidden';
            paymentFundInput.name = 'payment_fund_id';
            form.appendChild(paymentFundInput);
            console.log('✅ Created payment_fund_id input');
        }
        paymentFundInput.value = fundId;
        console.log('Set payment_fund_id =', fundId);

        let paymentBankInput = form.querySelector('input[name="payment_bank_id"]');
        if (!paymentBankInput) {
            paymentBankInput = document.createElement('input');
            paymentBankInput.type = 'hidden';
            paymentBankInput.name = 'payment_bank_id';
            form.appendChild(paymentBankInput);
            console.log('✅ Created payment_bank_id input');
        }
        paymentBankInput.value = bankId || '';
        console.log('Set payment_bank_id =', bankId || '');

        // إضافة المدفوع الإجمالي (للتوافق مع الكود القديم)
        let totalPaid = paidCash + paidBank;
        let paidInput = form.querySelector('input[name="paid"]');
        if (!paidInput) {
            paidInput = document.createElement('input');
            paidInput.type = 'hidden';
            paidInput.name = 'paid';
            form.appendChild(paidInput);
        }
        paidInput.value = totalPaid;
        ensureHiddenFormInput(form, 'empty_table_after_payment').value = $('#pos_empty_table_after_payment').prop('checked') ? '1' : '0';

        // Check for Edit ID
        let editId = $('#edit_order_id').val() || $('#selected_order_id').val();
        if (editId) {
            console.log('✏️ Edit Mode: ID', editId);
            let editIdInput = form.querySelector('input[name="edit_id"]');
            if (!editIdInput) {
                editIdInput = document.createElement('input');
                editIdInput.type = 'hidden';
                editIdInput.name = 'edit_id';
                form.appendChild(editIdInput);
            }
            editIdInput.value = editId;
        } else {
            let editIdInput = form.querySelector('input[name="edit_id"]');
            if (editIdInput) {
                editIdInput.remove();
            }
        }

        const existingSubmits = form.querySelectorAll('input[name="submit"]');
        existingSubmits.forEach(input => input.remove());

        const submitInput = document.createElement('input');
        submitInput.type = 'hidden';
        submitInput.name = 'submit';
        submitInput.value = action;
        form.appendChild(submitInput);
        ensureFormIdempotencyKey(form, action);

        console.log('➕ Added submit input with value:', action);

        let saveBtn = $(".pos-save-order-btn");
        let printBtn = $(".pos-pay-confirm-btn");

        if (saveBtn.length > 0) {
            saveBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> جاري الحفظ...');
        }
        if (printBtn.length > 0) {
            printBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> جاري الدفع...');
        }

        $('#paymentModal').modal('hide');

        console.log('📤 Submitting form to:', form.action);
        console.log('📊 Form method:', form.method);

        const formData = new FormData(form);
        console.log('📋 Form data:');
        for (let [key, value] of formData.entries()) {
            console.log(`  ${key}: ${value}`);
        }

        setTimeout(function() {
            try {
                // إرسال الفورم مباشرة بدون تأكيد
                HTMLFormElement.prototype.submit.call(form);
                console.log('✅ Form submitted successfully!');

            } catch (error) {
                console.error('❌ Error submitting form:', error);
                alert('حدث خطأ أثناء إرسال البيانات: ' + error.message);

                if (saveBtn.length > 0) {
                    saveBtn.prop('disabled', false).html('<i class="fas fa-save me-1"></i>حفظ الطلب');
                }
                if (printBtn.length > 0) {
                    printBtn.prop('disabled', false).html('<i class="fas fa-receipt me-1"></i>دفع وطباعة');
                }
            }
        }, 100);

        return true;
    };

    $('#barcodeInput').focus();

    // Keyboard shortcuts
    $(document).on('keydown', function(e) {
        // Ctrl + F or F3 for search focus
        if ((e.ctrlKey && e.key === 'f') || e.key === 'F3') {
            e.preventDefault();
            $filterInput.focus().select();
        }

        // Escape to clear search
        if (e.key === 'Escape') {
            if ($filterInput.is(':focus') && $filterInput.val() !== '') {
                $('#clearFilter').click();
            }
        }

        // Alt + B for barcode input focus
        if (e.altKey && e.key === 'b') {
            e.preventDefault();
            $('#barcodeInput').focus().select();
        }

        // Alt + S for search input focus
        if (e.altKey && e.key === 's') {
            e.preventDefault();
            $filterInput.focus().select();
        }
    });

    window.handleFormSubmit = function(form) {
        console.log('Form submit handler called');
        return true;
    };
}); // End of document.ready

// ========================================
// Form Validation
// ========================================
function validatePOSForm() {
    console.log('=== validatePOSForm() called ===');

    let items = $('#itemData .item-card-order');
    console.log('📊 Items in order:', items.length);

    if (items.length === 0) {
        console.log('⚠️ No items found in order');
        alert('يجب إضافة صنف واحد على الأقل للطلب');
        return false;
    }

    let itmnames = $('input[name="itmname[]"]');
    let itmqtys = $('input[name="itmqty[]"]');
    let itmprices = $('input[name="itmprice[]"]');

    console.log('📋 Form fields check:');
    console.log('  - Items names:', itmnames.length);
    console.log('  - Items quantities:', itmqtys.length);
    console.log('  - Items prices:', itmprices.length);

    if (itmnames.length === 0) {
        console.error('❌ No item names found!');
        alert('خطأ: لا توجد أصناف في النموذج');
        return false;
    }

    let pro_tybe = $('input[name="pro_tybe"]').val();
    let store_id = $('select[name="store_id"]').val();
    let acc2_id = $('select[name="acc2_id"]').val();
    let emp_id = $('select[name="emp_id"]').val();

    console.log('🔍 Required fields check:');
    console.log('  - pro_tybe:', pro_tybe);
    console.log('  - store_id:', store_id);
    console.log('  - acc2_id:', acc2_id);
    console.log('  - emp_id:', emp_id);

    if (!pro_tybe || pro_tybe == '0') {
        console.error('❌ pro_tybe is missing or zero');
        alert('خطأ: نوع الفاتورة غير محدد');
        return false;
    }

    if (!store_id || store_id == '0') {
        console.error('❌ store_id is missing or zero');
        alert('خطأ: يجب اختيار المخزن');
        return false;
    }

    if (!acc2_id || acc2_id == '0') {
        console.error('❌ acc2_id is missing or zero');
        alert('خطأ: يجب اختيار العميل');
        return false;
    }

    if (!emp_id || emp_id == '0') {
        console.error('❌ emp_id is missing or zero');
        alert('خطأ: يجب اختيار الموظف');
        return false;
    }

    console.log('✅ Validation passed - Items found:', items.length);
    return true;
}

function dis() {
    return validatePOSForm();
}

// ========================================
// Recent Orders Functions
// ========================================
function cleanupStaleRecentOrdersBackdrop() {
    const hasOpenOffcanvas = document.querySelector('.offcanvas.show, .offcanvas.showing');
    if (hasOpenOffcanvas) {
        return;
    }

    document.querySelectorAll('.offcanvas-backdrop').forEach((backdrop) => {
        backdrop.remove();
    });

    if (!document.querySelector('.modal.show')) {
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    }
}

function scheduleRecentOrdersBackdropCleanup() {
    window.setTimeout(cleanupStaleRecentOrdersBackdrop, 250);
    window.setTimeout(cleanupStaleRecentOrdersBackdrop, 650);
}

function showRecentOrdersOffcanvas() {
    const recentOrdersModal = document.getElementById('recentOrdersModal');
    if (!recentOrdersModal || typeof bootstrap === 'undefined' || !bootstrap.Offcanvas) {
        return null;
    }

    const offcanvas = typeof bootstrap.Offcanvas.getOrCreateInstance === 'function'
        ? bootstrap.Offcanvas.getOrCreateInstance(recentOrdersModal)
        : new bootstrap.Offcanvas(recentOrdersModal);

    offcanvas.show();
    return offcanvas;
}

function loadRecentOrders() {
    console.log('Loading recent orders...');
    $('#recentOrdersList').html(`
        <tr>
            <td colspan="8" class="text-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">جاري التحميل...</span>
                </div>
                <p class="mt-2">جاري تحميل الطلبات...</p>
            </td>
        </tr>
    `);

    $.ajax({
        url: 'ajax/get_recent_orders.php',
        type: 'GET',
        cache: false,
        data: { _: Date.now() },
        dataType: 'json',
        success: function(response) {
            console.log('AJAX Response:', response);

            if (response.success && response.orders && response.orders.length > 0) {
                let html = '';
                response.orders.forEach((order, index) => {
                    const tableId = parseInt(order.table_id || 0, 10);
                    const canRefund = order.can_refund === true || order.can_refund === 1 || order.can_refund === '1';
                    const canVoid = order.can_void === true || order.can_void === 1 || order.can_void === '1';
                    const canDelete = order.can_delete === true || order.can_delete === 1 || order.can_delete === '1';
                    const deleteButton = canDelete
                        ? `<button class="btn btn-danger delete-order" data-id="${order.id}" data-table-id="${tableId}" title="حذف">
                                <i class="fas fa-trash"></i>
                           </button>`
                        : `<button class="btn btn-outline-secondary" disabled title="لا يمكن حذف طلب مكتمل أو مدفوع من هنا">
                                <i class="fas fa-trash"></i>
                           </button>`;
                    const paidReversalButton = (canRefund || canVoid)
                        ? `<button type="button" class="btn btn-outline-danger reverse-paid-order" data-id="${order.id}" data-can-refund="${canRefund ? '1' : '0'}" data-can-void="${canVoid ? '1' : '0'}" onclick="reversePaidOrder(${order.id}, ${canRefund ? 'true' : 'false'}, ${canVoid ? 'true' : 'false'})" title="استرداد أو إلغاء مدفوع">
                                <i class="fas fa-undo"></i>
                           </button>`
                        : '';

                    html += `
                        <tr>
                            <td>${index + 1}</td>
                            <td><strong>${order.invoice_number}</strong></td>
                            <td>${order.date}</td>
                            <td>${order.customer_name}</td>
                            <td>
                                <span class="badge bg-info">${order.type}</span>
                            </td>
                            <td class="text-nowrap fw-bold text-success">
                                ${order.total.toFixed(2)} ج.م
                            </td>
                            <td>
                                <span class="badge ${order.status === 'مكتمل' ? 'bg-success' : 'bg-warning'}">
                                    ${order.status}
                                </span>
                            </td>
                            <td class="text-nowrap">
                                <div class="btn-group btn-group-sm" role="group">
                                    <button class="btn btn-warning edit-order" data-id="${order.id}" title="تعديل">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-secondary print-order" data-id="${order.id}" title="طباعة الفاتورة">
                                        <i class="fas fa-print"></i>
                                    </button>
                                    ${deleteButton}
                                    ${paidReversalButton}
                                </div>
                                ${order.notes ? `<span class="text-muted ms-2" title="${order.notes}"><i class="fas fa-sticky-note"></i></span>` : ''}
                            </td>
                        </tr>
                    `;
                });
                $('#recentOrdersList').html(html);
                console.log('Orders loaded successfully:', response.orders.length);
            } else {
                $('#recentOrdersList').html(`
                    <tr>
                        <td colspan="8" class="text-center py-5">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <p class="text-muted">لا توجد طلبات سابقة</p>
                            <small class="text-muted">سيظهر هنا آخر 10 طلبات بعد إنشاء أول طلب</small>
                        </td>
                    </tr>
                `);
                console.log('No orders found');
            }
        },
        error: function(xhr, status, error) {
            console.error('Error loading recent orders:', error);
            console.error('XHR status:', xhr.status);
            console.error('Response text:', xhr.responseText);

            $('#recentOrdersList').html(`
                <tr>
                    <td colspan="8" class="text-center py-5 text-danger">
                        <i class="fas fa-exclamation-triangle fa-2x mb-3"></i>
                        <p>حدث خطأ أثناء تحميل الطلبات</p>
                        <small class="d-block">${error}</small>
                        <button class="btn btn-sm btn-outline-primary mt-2" onclick="loadRecentOrders()">
                            <i class="fas fa-sync-alt me-1"></i> إعادة المحاولة
                        </button>
                    </td>
                </tr>
            `);
        }
    });
}

function editOrder(orderId) {
    console.log('Edit order:', orderId);
    window.location.href = 'pos_barcode.php?edit=' + orderId;
}

function deleteOrder(orderId, tableId) {
    if (confirm('هل أنت متأكد من حذف هذا الطلب؟ لا يمكن التراجع عن هذه العملية.')) {
        $.ajax({
            url: 'ajax/delete_order.php',
            type: 'POST',
            data: {
                order_id: orderId,
                table_id: parseInt(tableId || 0, 10),
                idempotency_key: createPOSIdempotencyKey('pos.order.cancel')
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    loadRecentOrders();
                    alert('تم حذف الطلب بنجاح');
                } else {
                    alert('حدث خطأ أثناء حذف الطلب: ' + (response.message || 'خطأ غير معروف'));
                }
            },
            error: function() {
                alert('حدث خطأ في الاتصال بالخادم');
            }
        });
    }
}

// Initialize recent orders functionality
$(document).ready(function() {
    const $recentOrdersModal = $('#recentOrdersModal');
    if ($recentOrdersModal.length) {
        $recentOrdersModal
            .off('.recentOrdersCleanup')
            .on('hidden.bs.offcanvas.recentOrdersCleanup', cleanupStaleRecentOrdersBackdrop)
            .on('hide.bs.offcanvas.recentOrdersCleanup', scheduleRecentOrdersBackdropCleanup);

        $(document)
            .off('click.recentOrdersCleanup', '#recentOrdersModal [data-bs-dismiss="offcanvas"]')
            .on('click.recentOrdersCleanup', '#recentOrdersModal [data-bs-dismiss="offcanvas"]', scheduleRecentOrdersBackdropCleanup);
    }

    $(document).on('click', '.recent-orders-btn, #recentOrdersBtn1, #recentOrdersBtn2', function(e) {
        e.preventDefault();
        console.log('Recent orders button clicked');
        showRecentOrdersOffcanvas();
        loadRecentOrders();
    });

    // Handle edit order button
    $(document).on('click', '.edit-order', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const orderId = $(this).data('id');
        console.log('Edit button clicked for order:', orderId);
        editOrder(orderId);
    });

    // Handle delete order button
    $(document).on('click', '.delete-order', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const orderId = $(this).data('id');
        const tableId = $(this).data('table-id');
        console.log('Delete button clicked for order:', orderId, 'table:', tableId);
        deleteOrder(orderId, tableId);
    });

    // Handle print order button
    $(document).on('click', '.print-order', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const orderId = $(this).data('id');
        console.log('Print button clicked for order:', orderId);
        window.open('print/receipt.php?id=' + orderId, '_blank');
    });

    // Load orders when offcanvas is shown
    $('#recentOrdersModal').on('shown.bs.offcanvas', function() {
        loadRecentOrders();
    });
});

// ========================================
// Daily Sales Report Print Function
// ========================================
function printDailySalesReport() {
    console.log('Opening daily sales report...');
    window.open('print/daily_sales_receipt.php', '_blank');
}



// ========================================
// Shift Management Functions
// ========================================
// Shift functions moved to pos_barcode.php inline script for better error handling

function closeShift() {
    if (confirm('هل أنت متأكد من إغلاق الشيفت؟')) {
        window.location.href = 'logout.php';
    }
}
