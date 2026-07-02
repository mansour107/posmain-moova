(function ($) {
    'use strict';

    var itemTypeLabels = {
        sellable: 'منتج للبيع',
        ingredient: 'مكوّن',
        packaging: 'تغليف',
        service: 'خدمة'
    };

    function currentType() {
        return $('#item_type').val() || 'sellable';
    }

    function unitLabel($select) {
        return $.trim($select.find('option:selected').text() || '—');
    }

    function setHiddenActive($hidden, $checkbox, active) {
        $hidden.val(active ? '1' : '0');
        $checkbox.prop('checked', !!active);
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
            $sellStorage.find('[data-role="left-unit"]').text(unitLabel($('#sell_unit_id')));
            $sellStorage.find('[data-role="right-unit"]').text(unitLabel($('#storage_unit_id')));
        }

        var $purchaseStorage = $('#purchase-storage-conversion');
        var showPurchaseStorage = purchaseActive
            && purchaseUnitId !== ''
            && storageUnitId !== ''
            && purchaseUnitId !== storageUnitId;

        $purchaseStorage.toggleClass('d-none', !showPurchaseStorage);
        if (showPurchaseStorage) {
            $purchaseStorage.find('[data-role="left-unit"]').text(unitLabel($('#purchase_unit_id')));
            $purchaseStorage.find('[data-role="right-unit"]').text(unitLabel($('#storage_unit_id')));
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

    function swapFactor($input) {
        var value = parseFloat($input.val());
        if (!isFinite(value) || value <= 0) {
            return;
        }
        $input.val((1 / value).toFixed(6).replace(/\.?0+$/, ''));
    }

    function activatePurchaseSection() {
        $('#purchase_section_checkbox').prop('checked', true);
        refreshSectionStates();
        refreshConversions();
        refreshSummary();
    }

    function validateBeforeSubmit() {
        var type = currentType();
        var sellActive = $('#sell_active').val() === '1';
        var purchaseActive = $('#purchase_active').val() === '1';
        var hasVariants = $('#variantRowsContainer .variant-row').length > 0;

        if ((type === 'ingredient' || type === 'packaging') && !parseInt($('#storage_unit_id').val(), 10)) {
            alert('يرجى اختيار وحدة التخزين');
            return false;
        }

        if (sellActive && !hasVariants) {
            var sellPrice = parseFloat($('#sell_price1').val());
            if (!isFinite(sellPrice) || sellPrice <= 0) {
                alert('يرجى إدخال سعر البيع');
                return false;
            }
        }

        if (purchaseActive) {
            var cost = parseFloat($('#purchase_cost').val());
            if (!isFinite(cost) || cost <= 0) {
                alert('يرجى إدخال تكلفة الشراء للوحدة');
                return false;
            }
            if (!$('#purchase_unit_id').val()) {
                alert('يرجى اختيار وحدة الشراء');
                return false;
            }
        }

        return true;
    }

    $(function () {
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

        $('#purchase-only-fields').on('focusin click', 'input, select', function () {
            if (!$('#purchase_section_checkbox').is(':checked')) {
                activatePurchaseSection();
            }
        });

        $('.item-profile-unit-select').on('change', refreshConversions);
        $('.item-profile-unit-select').on('change', refreshSummary);
        $('#sell_unit_id').on('change', function () {
            if ($('#purchase_active').val() !== '1') {
                $('#storage_unit_id').val($(this).val());
            }
            refreshConversions();
            refreshSummary();
        });

        $('.item-unit-conversion__swap').on('click', function () {
            var target = $(this).data('swap-target');
            if (target === 'sell-storage') {
                swapFactor($('#sell_storage_factor'));
            } else if (target === 'purchase-storage') {
                swapFactor($('#purchase_storage_factor'));
            }
            refreshConversions();
        });

        $('#item-main-form').on('submit', function (event) {
            var $sellBarcode = $('input[name="sell_barcode"]');
            if ($sellBarcode.length) {
                $sellBarcode.val($.trim($('input[name="barcode"]').val() || ''));
            }
            if (!validateBeforeSubmit()) {
                event.preventDefault();
            }
        });

        refreshSectionStates();
        refreshConversions();
        refreshSummary();
    });
})(jQuery);
