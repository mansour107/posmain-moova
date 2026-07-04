(function ($) {
    'use strict';

    var itemTypeLabels = {
        sellable: 'منتج للبيع',
        ingredient: 'مكوّن',
        packaging: 'تغليف',
        service: 'خدمة'
    };

    var conversionConfig = {
        'sell-storage': {
            leftUnit: function (swapped) {
                return swapped ? $('#sell_unit_id') : $('#storage_unit_id');
            },
            rightUnit: function (swapped) {
                return swapped ? $('#storage_unit_id') : $('#sell_unit_id');
            },
            swapField: '#sell_storage_swapped'
        },
        'purchase-storage': {
            leftUnit: function (swapped) {
                return swapped ? $('#storage_unit_id') : $('#purchase_unit_id');
            },
            rightUnit: function (swapped) {
                return swapped ? $('#purchase_unit_id') : $('#storage_unit_id');
            },
            swapField: '#purchase_storage_swapped'
        }
    };

    function currentType() {
        return $('#item_type').val() || 'sellable';
    }

    function currentCostSource() {
        return $('input[name="cost_source"]:checked').val() || 'direct';
    }

    function selectCostSource(source) {
        var $field = $('input[name="cost_source"][value="' + source + '"]');
        if ($field.length) {
            $field.prop('checked', true);
        }
    }

    function unitLabel($field) {
        if (!$field || !$field.length) {
            return '—';
        }
        var $combobox = $field.closest('.item-unit-combobox');
        if ($combobox.length) {
            return $.trim($combobox.find('.item-unit-combobox__input').val() || '—');
        }
        if ($field.is('select')) {
            return $.trim($field.find('option:selected').text() || '—');
        }
        return '—';
    }

    function formatFactor(value) {
        if (!isFinite(value) || value <= 0) {
            return '1';
        }
        var rounded = Math.round(value);
        if (rounded > 0 && Math.abs(value - rounded) < 1e-9) {
            return String(rounded);
        }
        return value.toFixed(6).replace(/\.?0+$/, '');
    }

    function formatNonNegativeDecimal(value) {
        if (!isFinite(value) || value <= 0) {
            return '0';
        }
        var rounded = Math.round(value);
        if (rounded > 0 && Math.abs(value - rounded) < 1e-9) {
            return String(rounded);
        }
        return value.toFixed(6).replace(/\.?0+$/, '');
    }

    function parsePositiveFactor($input) {
        var value = parseFloat($input.val());
        if (!isFinite(value) || value <= 0) {
            return null;
        }
        return value;
    }

    function isDirectionSwapped($block) {
        return $block.hasClass('is-direction-swapped');
    }

    function getConversionType($block) {
        return String($block.data('conversion') || '');
    }

    function updateConversionLabels($block) {
        var conversionType = getConversionType($block);
        var config = conversionConfig[conversionType];
        if (!config) {
            return;
        }
        var swapped = isDirectionSwapped($block);
        var leftLabel = unitLabel(config.leftUnit(swapped));
        var rightLabel = unitLabel(config.rightUnit(swapped));
        $block.find('[data-role="left-unit"]').text(leftLabel);
        $block.find('[data-role="right-unit"]').text(rightLabel);

        var factor = formatFactor(parsePositiveFactor($block.find('.item-unit-conversion__factor')) || 1);
        var plain = leftLabel !== '—' && rightLabel !== '—'
            ? '1 ' + leftLabel + ' = ' + factor + ' ' + rightLabel
            : 'تحويل الوحدات';
        $block.attr('aria-label', plain);
    }

    function setHiddenActive($hidden, $checkbox, active) {
        $hidden.val(active ? '1' : '0');
        $checkbox.prop('checked', !!active);
    }

    function syncUnitComboboxDisplay(fieldId, unitId) {
        var value = String(unitId || '');
        var $hidden = $('#' + fieldId);
        if (!$hidden.length) {
            return;
        }
        var $combobox = $hidden.closest('.item-unit-combobox');
        if (!$combobox.length) {
            $hidden.val(value);
            return;
        }
        var matchName = '';
        $combobox.find('.item-unit-combobox__option').each(function () {
            if (String($(this).data('id') || '') === value) {
                matchName = String($(this).data('name') || $(this).text() || '');
            }
        });
        $hidden.val(value);
        $combobox.find('.item-unit-combobox__input').val(matchName);
    }

    function refreshSectionStates() {
        var type = currentType();
        var $sellSection = $('#item-sell-section');
        var $purchaseSection = $('#item-purchase-section');
        var $sellToggleWrap = $('#sell-section-toggle-wrap');
        var $purchaseCard = $('#purchase-section-card');
        var sellAlwaysOn = type === 'sellable' || type === 'service';
        var purchaseHidden = type === 'service';

        $sellSection.toggleClass('d-none', false);
        $purchaseSection.toggleClass('d-none', purchaseHidden);

        if (sellAlwaysOn) {
            $sellToggleWrap.addClass('d-none');
            $('#sell-section-body').closest('.item-unit-profile-section').removeClass('is-disabled');
            setHiddenActive($('#sell_active'), $('#sell_section_checkbox'), true);
        } else {
            $sellToggleWrap.removeClass('d-none');
            var sellActive = $('#sell_section_checkbox').is(':checked');
            setHiddenActive($('#sell_active'), $('#sell_section_checkbox'), sellActive);
            $('#sell-section-body').closest('.item-unit-profile-section').toggleClass('is-disabled', !sellActive);
        }

        if (purchaseHidden) {
            setHiddenActive($('#purchase_active'), $('#purchase_section_checkbox'), false);
            refreshCostSourceState();
            refreshSummary();
            return;
        }

        var purchaseActive = $('#purchase_section_checkbox').is(':checked');
        var storageOnlyType = type === 'ingredient' || type === 'packaging';
        setHiddenActive($('#purchase_active'), $('#purchase_section_checkbox'), purchaseActive);
        $purchaseCard.toggleClass('is-disabled', !purchaseActive && !storageOnlyType);
        $purchaseCard.toggleClass('is-activatable', !purchaseActive);
        $('#purchase-only-fields').toggleClass('is-disabled', !purchaseActive);
        $('#purchase-only-fields').css('pointer-events', purchaseActive ? '' : 'none');
        $('#purchase-only-fields').css('opacity', purchaseActive ? '' : '0.55');

        if (type === 'ingredient' || type === 'packaging') {
            $('#storage_unit_id').prop('required', true);
        } else {
            $('#storage_unit_id').prop('required', false);
        }
        refreshCostSourceState();
        refreshSummary();
    }

    function refreshConversions() {
        var sellUnitId = String($('#sell_unit_id').val() || '');
        var storageUnitId = String($('#storage_unit_id').val() || '');
        var purchaseUnitId = String($('#purchase_unit_id').val() || '');
        var purchaseActive = $('#purchase_active').val() === '1';

        var $sellStorage = $('#sell-storage-conversion');
        var showSellStorage = sellUnitId !== ''
            && storageUnitId !== ''
            && sellUnitId !== storageUnitId;

        $sellStorage.toggleClass('d-none', !showSellStorage);
        if (showSellStorage) {
            updateConversionLabels($sellStorage);
        }

        var $purchaseStorage = $('#purchase-storage-conversion');
        var showPurchaseStorage = purchaseActive
            && purchaseUnitId !== ''
            && storageUnitId !== ''
            && purchaseUnitId !== storageUnitId;

        $purchaseStorage.toggleClass('d-none', !showPurchaseStorage);
        if (showPurchaseStorage) {
            updateConversionLabels($purchaseStorage);
        }

        $('#unitImpactPreview').toggleClass('d-none', !(showSellStorage || showPurchaseStorage));
    }

    function formatPriceDisplay(value) {
        var num = parseFloat(value);
        if (!isFinite(num) || num <= 0) {
            return '—';
        }
        return formatCostPerUnit(num);
    }

    function profitMarginPercent(sellPrice, cost) {
        var sell = parseFloat(sellPrice);
        var costValue = parseFloat(cost);
        if (!isFinite(sell) || sell <= 0) {
            return null;
        }
        if (!isFinite(costValue) || costValue <= 0) {
            return null;
        }
        return ((sell - costValue) / costValue) * 100;
    }

    function formatMarginPercent(margin) {
        if (margin === null || !isFinite(margin)) {
            return '—';
        }
        var rounded = Math.round(margin * 10) / 10;
        return String(rounded).replace(/\.0$/, '') + '%';
    }

    function extraUnitsList() {
        var units = [];
        var sellId = String($('#sell_unit_id').val() || '');
        var storageId = String($('#storage_unit_id').val() || '');
        var purchaseId = String($('#purchase_unit_id').val() || '');
        var storageLabel = unitLabel($('#storage_unit_id'));
        var purchaseLabel = unitLabel($('#purchase_unit_id'));

        if (storageId !== '' && storageId !== sellId && storageLabel !== '—') {
            units.push(storageLabel);
        }
        if ($('#purchase_active').val() === '1'
            && purchaseId !== ''
            && purchaseId !== storageId
            && purchaseId !== sellId
            && purchaseLabel !== '—') {
            units.push(purchaseLabel);
        }

        return units;
    }

    function applyMarginState($target, margin) {
        var text = formatMarginPercent(margin);
        $target.text(text);
        $target.toggleClass('is-negative', margin !== null && margin < 0);
        $target.toggleClass('is-empty', margin === null);
    }

    function refreshProfitMargin() {
        var margin = profitMarginPercent($('#sell_price1').val(), $('#cost_per_unit_value').val());
        applyMarginState($('#sell_profit_margin'), margin);
        applyMarginState($('#summaryProfitMargin'), margin);
    }

    function refreshSanitySummary() {
        var sellUnit = finalSellUnitLabel();
        $('#summarySellUnit').text(sellUnit);
        $('#summarySellPrice').text(formatPriceDisplay($('#sell_price1').val()));
        $('#summaryCostPerSellUnit').text(formatPriceDisplay($('#cost_per_unit_value').val()));

        var extras = extraUnitsList();
        if (extras.length) {
            $('#summaryExtraUnitsLine').removeClass('d-none');
            $('#summaryExtraUnits').text(extras.join('، '));
        } else {
            $('#summaryExtraUnitsLine').addClass('d-none');
            $('#summaryExtraUnits').text('—');
        }

        refreshProfitMargin();
    }

    function refreshSellPricingVisibility() {
        var type = currentType();
        var sellAlwaysOn = type === 'sellable' || type === 'service';
        var sellActive = sellAlwaysOn || $('#sell_section_checkbox').is(':checked');
        $('#sell-price-cost-row, #sell-margin-row, #sell-cost-source-block').toggleClass('d-none', !sellActive);
    }

    function refreshSummary() {
        refreshSellPricingVisibility();
        refreshSanitySummary();
    }

    function formatCostPerUnit(value) {
        if (!isFinite(value) || value < 0) {
            return '0';
        }
        if (value === 0) {
            return '0';
        }
        var rounded = Math.round(value * 1000) / 1000;
        if (Math.abs(rounded - Math.round(rounded)) < 1e-9) {
            return String(Math.round(rounded));
        }
        return rounded.toFixed(3).replace(/\.?0+$/, '');
    }

    function finalSellUnitLabel() {
        var sellActive = $('#sell_active').val() === '1';
        if (sellActive) {
            var sellLabel = unitLabel($('#sell_unit_id'));
            if (sellLabel !== '—') {
                return sellLabel;
            }
        }
        return unitLabel($('#storage_unit_id'));
    }

    function sellUnitsPerStockUnit() {
        var sellUnitId = String($('#sell_unit_id').val() || '');
        var storageUnitId = String($('#storage_unit_id').val() || '');
        if (sellUnitId === '' || storageUnitId === '' || sellUnitId === storageUnitId) {
            return 1;
        }

        var factor = parsePositiveFactor($('#sell_storage_factor')) || 1;
        var swapped = $('#sell-storage-conversion').hasClass('is-direction-swapped');
        return swapped ? (1 / factor) : factor;
    }

    function sellStockFactor() {
        var unitsPerStock = sellUnitsPerStockUnit();
        if (!isFinite(unitsPerStock) || unitsPerStock <= 0) {
            return 1;
        }
        return 1 / unitsPerStock;
    }

    function purchaseCostPerStockUnit() {
        var purchaseCost = parseFloat($('#purchase_cost').val());
        if (!isFinite(purchaseCost) || purchaseCost < 0) {
            purchaseCost = 0;
        }

        var factor = parsePositiveFactor($('#purchase_storage_factor')) || 1;
        var swapped = $('#purchase-storage-conversion').hasClass('is-direction-swapped');
        var stockFactor = swapped ? (1 / factor) : factor;
        if (!isFinite(stockFactor) || stockFactor <= 0) {
            stockFactor = 1;
        }

        return purchaseCost / stockFactor;
    }

    function purchaseCostPerSellUnit() {
        return purchaseCostPerStockUnit() * sellStockFactor();
    }

    function recipeCostMeta() {
        var $block = $('#sell-cost-source-block');
        return {
            available: String($block.data('recipe-available') || '0') === '1',
            hasCost: String($block.data('recipe-has-cost') || '0') === '1'
        };
    }

    function updateCostSourceChoiceStates() {
        var purchaseActive = $('#purchase_active').val() === '1';
        var recipeMeta = recipeCostMeta();
        var $purchaseChoice = $('#cost-source-purchase-choice');
        var $recipeChoice = $('#cost-source-recipe-choice');
        var $purchaseInput = $purchaseChoice.find('input[type="radio"]');
        var $recipeInput = $recipeChoice.find('input[type="radio"]');

        $purchaseChoice.toggleClass('is-disabled', !purchaseActive);
        $purchaseInput.prop('disabled', !purchaseActive);
        if (!purchaseActive && $purchaseInput.is(':checked')) {
            selectCostSource('direct');
        }

        var recipeEnabled = recipeMeta.available && recipeMeta.hasCost;
        $recipeChoice.toggleClass('is-disabled', !recipeEnabled);
        $recipeInput.prop('disabled', !recipeEnabled);
        if (!recipeEnabled && $recipeInput.is(':checked')) {
            selectCostSource('direct');
        }

        $('.item-cost-source-choice').removeClass('is-active');
        $('.item-cost-source-choice input[type="radio"]:checked').closest('.item-cost-source-choice').addClass('is-active');
    }

    function refreshCostSourceState() {
        updateCostSourceChoiceStates();
        var source = currentCostSource();

        var sellUnit = finalSellUnitLabel();
        var storageUnit = unitLabel($('#storage_unit_id'));
        var purchaseUnit = unitLabel($('#purchase_unit_id'));
        var purchaseActive = $('#purchase_active').val() === '1';
        var samePurchaseStorage = purchaseUnit === storageUnit || !purchaseActive
            || String($('#purchase_unit_id').val() || '') === String($('#storage_unit_id').val() || '');
        var sameSellStorage = String($('#sell_unit_id').val() || '') === String($('#storage_unit_id').val() || '');

        $('#purchase_cost_label').text(
            purchaseActive && purchaseUnit !== '—'
                ? 'تكلفة الشراء لكل ' + purchaseUnit
                : 'تكلفة الشراء لكل وحدة'
        );

        var $value = $('#cost_per_unit_value');
        var $directHidden = $('#direct_cost_price');
        var perUnitCost = 0;
        var hint = '';

        if (source === 'purchase') {
            perUnitCost = purchaseCostPerSellUnit();
            $value.prop('readonly', true).removeClass('is-editable-cost');
            $('#cost_per_unit_label').text('تكلفة لكل ' + sellUnit);
            if (!purchaseActive) {
                hint = 'فعّل قسم الشراء وأدخل تكلفة لكل ' + purchaseUnit + '.';
            } else if (samePurchaseStorage && sameSellStorage) {
                hint = 'نفس تكلفة الشراء لكل ' + purchaseUnit + '.';
            } else if (sameSellStorage) {
                hint = 'تُحسب من تكلفة الشراء لكل ' + purchaseUnit + ' إلى تكلفة لكل ' + storageUnit + '.';
            } else {
                hint = 'تُحسب من تكلفة الشراء لكل ' + purchaseUnit
                    + ' → ' + storageUnit
                    + ' → ' + sellUnit + '.';
            }
        } else if (source === 'direct') {
            perUnitCost = parseFloat($directHidden.val()) || parseFloat($value.val()) || 0;
            $value.prop('readonly', false).addClass('is-editable-cost').val(formatCostPerUnit(perUnitCost));
            $directHidden.val($value.val());
            $('#cost_per_unit_label').text('تكلفة لكل ' + sellUnit);
            hint = 'أدخل تكلفة كل ' + sellUnit + ' مباشرة.';
        } else if (source === 'recipe') {
            perUnitCost = parseFloat($('#recipe_cost_price').val()) || 0;
            $value.prop('readonly', true).removeClass('is-editable-cost');
            $('#cost_per_unit_label').text('تكلفة لكل ' + sellUnit);
            hint = perUnitCost > 0
                ? 'تُقرأ من تكلفة مكونات الوصفة لكل ' + sellUnit + '.'
                : 'لا توجد وصفة نشطة — أضف وصفة لحساب التكلفة تلقائياً.';
        }

        if (source !== 'direct') {
            $value.val(formatCostPerUnit(perUnitCost));
        }
        $('#cost_per_unit_hint').text(hint);
        refreshSanitySummary();
    }

    function syncDirectCostFromVisible() {
        if (currentCostSource() !== 'direct') {
            return;
        }
        var value = $('#cost_per_unit_value').val();
        $('#direct_cost_price').val(value);
    }

    function syncConversionSwapField($block) {
        var conversionType = getConversionType($block);
        var config = conversionConfig[conversionType];
        if (!config || !config.swapField) {
            return;
        }
        $(config.swapField).val(isDirectionSwapped($block) ? '1' : '0');
    }

    function initConversionDisplayFactors() {
        $('.item-unit-conversion').each(function () {
            var $block = $(this);
            var $input = $block.find('.item-unit-conversion__factor');
            var value = parsePositiveFactor($input);
            if (value !== null) {
                $input.val(formatFactor(value));
            }
            syncConversionSwapField($block);
        });
    }

    function swapConversionDirection($block) {
        var $input = $block.find('.item-unit-conversion__factor');
        var display = parsePositiveFactor($input);
        if (display === null) {
            return;
        }
        $block.toggleClass('is-direction-swapped');
        $input.val(formatFactor(1 / display));
        syncConversionSwapField($block);
        updateConversionLabels($block);
    }

    function activatePurchaseSection() {
        $('#purchase_section_checkbox').prop('checked', true);
        if (currentCostSource() === 'direct' && !(parseFloat($('#cost_per_unit_value').val()) > 0)) {
            selectCostSource('purchase');
        }
        refreshSectionStates();
        refreshConversions();
        refreshSummary();
    }

    var validationToastTimer = null;

    function resolveValidationField(field) {
        var $field = field && field.jquery ? field : $(field);
        if (!$field.length) {
            return $field;
        }
        if ($field.hasClass('item-unit-combobox__value') || $field.hasClass('item-profile-unit-select')) {
            return $field.closest('.item-unit-combobox').find('.item-unit-combobox__input');
        }
        if ($field.is('input[type="hidden"]')) {
            var $comboboxInput = $('#' + $field.attr('id') + '_input');
            if ($comboboxInput.length) {
                return $comboboxInput;
            }
        }
        return $field;
    }

    function clearValidationErrors() {
        $('.item-editor-field-error').removeClass('item-editor-field-error');
        $('.variant-row.is-field-error').removeClass('is-field-error');
    }

    function markValidationError(field) {
        var $field = resolveValidationField(field);
        if (!$field.length) {
            return $field;
        }
        $field.addClass('item-editor-field-error');
        var $variantRow = $field.closest('.variant-row');
        if ($variantRow.length) {
            $variantRow.addClass('is-field-error');
        }
        return $field;
    }

    function showValidationToast(message) {
        var $toast = $('#item-editor-validation-toast');
        if (!$toast.length) {
            $toast = $('<div id="item-editor-validation-toast" class="item-editor-validation-toast" role="alert" aria-live="assertive"></div>');
            $('body').append($toast);
        }
        $toast.text(message);
        $toast.addClass('is-visible');
        if (validationToastTimer) {
            window.clearTimeout(validationToastTimer);
        }
        validationToastTimer = window.setTimeout(function () {
            $toast.removeClass('is-visible');
        }, 4200);
    }

    function showValidationFailure(message, fields) {
        clearValidationErrors();
        var $markedFields = [];
        (fields || []).forEach(function (field) {
            var $marked = markValidationError(field);
            if ($marked && $marked.length) {
                $markedFields.push($marked);
            }
        });
        showValidationToast(message);
        if ($markedFields.length) {
            var $first = $markedFields[0];
            $('html, body').animate({
                scrollTop: Math.max(0, $first.offset().top - 120)
            }, 220);
            window.setTimeout(function () {
                $first.trigger('focus');
            }, 240);
        }
        return false;
    }

    window.ItemEditorValidation = {
        clear: clearValidationErrors,
        fail: showValidationFailure,
        mark: markValidationError,
        toast: showValidationToast
    };

    function validateBeforeSubmit() {
        var type = currentType();
        var sellActive = $('#sell_active').val() === '1';
        var purchaseActive = $('#purchase_active').val() === '1';
        var costSource = currentCostSource();
        var hasVariants = $('#variantRowsContainer .variant-row').length > 0;

        if ((type === 'ingredient' || type === 'packaging') && !parseInt($('#storage_unit_id').val(), 10)) {
            return showValidationFailure('يرجى اختيار وحدة التخزين', ['#storage_unit_id']);
        }

        if (sellActive && !hasVariants) {
            var sellPrice = parseFloat($('#sell_price1').val());
            if (!isFinite(sellPrice) || sellPrice <= 0) {
                return showValidationFailure('يرجى إدخال سعر البيع', ['#sell_price1']);
            }
        }

        if (costSource === 'purchase') {
            if (!purchaseActive) {
                return showValidationFailure('تكلفة الشراء تتطلب تفعيل قسم الشراء', ['#purchase_section_checkbox']);
            }
            var cost = parseFloat($('#purchase_cost').val());
            if (!isFinite(cost) || cost <= 0) {
                return showValidationFailure('يرجى إدخال تكلفة الشراء لكل وحدة', ['#purchase_cost']);
            }
            if (!$('#purchase_unit_id').val()) {
                return showValidationFailure('يرجى اختيار وحدة الشراء', ['#purchase_unit_id']);
            }
        }

        if (costSource === 'direct') {
            syncDirectCostFromVisible();
            var directCost = parseFloat($('#direct_cost_price').val());
            if (!isFinite(directCost) || directCost < 0) {
                return showValidationFailure('يرجى إدخال تكلفة لكل وحدة', ['#cost_per_unit_value']);
            }
        }

        if (costSource === 'recipe') {
            var recipeCost = parseFloat($('#recipe_cost_price').val());
            if (!isFinite(recipeCost) || recipeCost <= 0) {
                return showValidationFailure('يرجى اختيار وصفة بها تكلفة محسوبة أو غيّر مصدر التكلفة', ['#cost-source-recipe-choice']);
            }
        }

        clearValidationErrors();
        return true;
    }

    function disableNumberInputWheelScroll() {
        var shell = document.querySelector('.item-editor-shell');
        if (!shell) {
            return;
        }

        shell.addEventListener('wheel', function (event) {
            var target = event.target;
            if (target && target.tagName === 'INPUT' && target.type === 'number') {
                event.preventDefault();
            }
        }, { passive: false, capture: true });
    }

    $(function () {
        disableNumberInputWheelScroll();
        initConversionDisplayFactors();
        refreshCostSourceState();

        $('.item-type-choice').on('click', function () {
            var type = $(this).data('item-type');
            var previousType = currentType();
            $('.item-type-choice').removeClass('active');
            $(this).addClass('active');
            $('#item_type').val(type);
            if (type === 'service') {
                $('#track_stock').prop('checked', false);
            }
            if ((type === 'ingredient' || type === 'packaging')
                && (previousType === 'sellable' || previousType === 'service')) {
                var sellPrice = parseFloat($('#sell_price1').val());
                if (!isFinite(sellPrice) || sellPrice <= 0) {
                    $('#sell_section_checkbox').prop('checked', false);
                }
            }
            refreshSectionStates();
            refreshConversions();
            refreshSummary();
            refreshCostSourceState();
        });

        $('#sell_section_checkbox').on('change', function () {
            refreshSectionStates();
            refreshConversions();
            refreshSummary();
            refreshCostSourceState();
        });

        $('#purchase_section_checkbox').on('change', function () {
            if ($(this).is(':checked') && currentCostSource() === 'direct' && !(parseFloat($('#cost_per_unit_value').val()) > 0)) {
                selectCostSource('purchase');
            }
            refreshSectionStates();
            refreshConversions();
            refreshSummary();
            refreshCostSourceState();
        });

        $('#purchase-section-header').on('click', function (event) {
            if ($(event.target).closest('input, label, button, select, a').length) {
                return;
            }
            if (!$('#purchase_section_checkbox').is(':checked')) {
                activatePurchaseSection();
            }
        });

        $('#purchase-only-fields').on('focusin click', 'input:not([type="hidden"]), select, .item-unit-combobox__input', function () {
            if (!$('#purchase_section_checkbox').is(':checked')) {
                activatePurchaseSection();
            }
        });

        $('.item-profile-unit-select').on('change', refreshConversions);
        $('.item-profile-unit-select').on('change', refreshSummary);
        $('.item-profile-unit-select').on('change', refreshCostSourceState);
        $('input[name="cost_source"]').on('change', function () {
            updateCostSourceChoiceStates();
            refreshCostSourceState();
        });
        $('#purchase_cost, #purchase_storage_factor, #sell_storage_factor, #cost_per_unit_value, #sell_price1').on('input change', function () {
            syncDirectCostFromVisible();
            refreshCostSourceState();
            if ($(this).is('.item-unit-conversion__factor')) {
                var $block = $(this).closest('.item-unit-conversion');
                if ($block.length) {
                    updateConversionLabels($block);
                }
            }
        });
        $('#sell_unit_id').on('change', function () {
            if ($('#purchase_active').val() !== '1') {
                syncUnitComboboxDisplay('storage_unit_id', $(this).val());
            }
            refreshConversions();
            refreshSummary();
            refreshCostSourceState();
        });

        $('.item-unit-conversion__swap').on('click', function () {
            var $block = $(this).closest('.item-unit-conversion');
            swapConversionDirection($block);
            refreshCostSourceState();
        });

        $('#item-main-form').on('submit', function (event) {
            var $sellBarcode = $('input[name="sell_barcode"]');
            if ($sellBarcode.length) {
                $sellBarcode.val($.trim($('input[name="barcode"]').val() || ''));
            }
            syncDirectCostFromVisible();
            if (!validateBeforeSubmit()) {
                event.preventDefault();
                return;
            }
        });

        $(document).on('input change', '.item-editor-shell input, .item-editor-shell select, .item-editor-shell textarea', function () {
            var $field = $(this);
            $field.removeClass('item-editor-field-error');
            $field.closest('.variant-row').removeClass('is-field-error');
        });

        refreshSectionStates();
        refreshConversions();
        refreshSummary();
        refreshCostSourceState();
    });
})(jQuery);
