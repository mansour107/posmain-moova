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
        $block.find('[data-role="left-unit"]').text(unitLabel(config.leftUnit(swapped)));
        $block.find('[data-role="right-unit"]').text(unitLabel(config.rightUnit(swapped)));
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
    }

    function refreshConversions() {
        var type = currentType();
        var sellActive = $('#sell_active').val() === '1';
        var purchaseActive = $('#purchase_active').val() === '1';
        var sellUnitId = String($('#sell_unit_id').val() || '');
        var storageUnitId = String($('#storage_unit_id').val() || '');
        var purchaseUnitId = String($('#purchase_unit_id').val() || '');

        var $sellStorage = $('#sell-storage-conversion');
        var showSellStorage = sellActive
            && (type === 'sellable' || type === 'service' || sellActive)
            && sellUnitId !== ''
            && storageUnitId !== ''
            && sellUnitId !== storageUnitId
            && (type === 'sellable' || type === 'service' || $('#sell_section_checkbox').is(':checked'));

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
    }

    function refreshSummary() {
        var type = currentType();
        $('#summaryItemType').text(itemTypeLabels[type] || type);
        $('#summaryBaseUnit').text(unitLabel($('#storage_unit_id')));
        var unitCount = 1;
        if ($('#purchase_active').val() === '1' && $('#purchase_unit_id').val() !== $('#storage_unit_id').val()) {
            unitCount += 1;
        }
        if ($('#sell_active').val() === '1' && $('#sell_unit_id').val() !== $('#storage_unit_id').val()) {
            unitCount += 1;
        }
        $('#summaryUnitCount').text(String(unitCount));
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

        if (purchaseActive) {
            var cost = parseFloat($('#purchase_cost').val());
            if (!isFinite(cost) || cost <= 0) {
                return showValidationFailure('يرجى إدخال تكلفة الشراء للوحدة', ['#purchase_cost']);
            }
            if (!$('#purchase_unit_id').val()) {
                return showValidationFailure('يرجى اختيار وحدة الشراء', ['#purchase_unit_id']);
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
        });

        $('#sell_section_checkbox').on('change', function () {
            refreshSectionStates();
            refreshConversions();
            refreshSummary();
        });

        $('#purchase_section_checkbox').on('change', function () {
            refreshSectionStates();
            refreshConversions();
            refreshSummary();
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
        $('#sell_unit_id').on('change', function () {
            if ($('#purchase_active').val() !== '1') {
                syncUnitComboboxDisplay('storage_unit_id', $(this).val());
            }
            refreshConversions();
            refreshSummary();
        });

        $('.item-unit-conversion__swap').on('click', function () {
            var $block = $(this).closest('.item-unit-conversion');
            swapConversionDirection($block);
        });

        $('#item-main-form').on('submit', function (event) {
            var $sellBarcode = $('input[name="sell_barcode"]');
            if ($sellBarcode.length) {
                $sellBarcode.val($.trim($('input[name="barcode"]').val() || ''));
            }
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
    });
})(jQuery);
