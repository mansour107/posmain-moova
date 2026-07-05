(function ($) {
    'use strict';

    function currentType() {
        return $('#item_type').val() || 'sellable';
    }

    function setHiddenActive($hidden, $checkbox, active) {
        $hidden.val(active ? '1' : '0');
        if ($checkbox && $checkbox.length) {
            $checkbox.prop('checked', !!active);
        }
    }

    function sellIsAlwaysOn(type) {
        return type === 'sellable' || type === 'made' || type === 'service';
    }

    function refreshSectionStates() {
        var type = currentType();

        if (sellIsAlwaysOn(type)) {
            setHiddenActive($('#sell_active'), null, true);
        } else {
            // Raw materials/packaging: selling is optional — active only when a price is entered.
            var sellPrice = parseFloat($('#sell_price1').val());
            setHiddenActive($('#sell_active'), null, isFinite(sellPrice) && sellPrice > 0);
        }

        $('#track_stock').val(type === 'service' || type === 'made' ? '0' : '1');

        refreshSellPricingVisibility();
        refreshSummary();
    }

    function formatPriceDisplay(value) {
        var num = parseFloat(value);
        if (!isFinite(num) || num <= 0) {
            return '—';
        }
        return formatCostPerUnit(num);
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

    function applyMarginState($target, margin) {
        var text = formatMarginPercent(margin);
        $target.text(text);
        $target.toggleClass('is-negative', margin !== null && margin < 0);
        $target.toggleClass('is-empty', margin === null);
    }

    function refreshProfitMargin() {
        var margin = profitMarginPercent($('#sell_price1').val(), $('#direct_cost_price').val());
        applyMarginState($('#sell_profit_margin'), margin);
        applyMarginState($('#summaryProfitMargin'), margin);
    }

    function countVariantRows() {
        return $('#variantRowsContainer .variant-row').length;
    }

    function refreshParentPricingVisibility() {
        $('#item-pricing-section').toggleClass('d-none', countVariantRows() > 0);
    }

    function refreshVariantRowMargin($row) {
        var $margin = $row.find('.variant-card__margin');
        if (!$margin.length) {
            return null;
        }
        var sellPrice = $row.find('.variant-sell-price-input').val();
        var cost = $row.find('input[name="variant_cost_price[]"]').val();
        var margin = profitMarginPercent(sellPrice, cost);
        var text = margin === null ? '—' : formatMarginPercent(margin);
        $margin.text('هامش: ' + text);
        $margin.toggleClass('is-negative', margin !== null && margin < 0);
        $margin.toggleClass('is-empty', margin === null);
        return margin;
    }

    function refreshVariantMargins() {
        var margins = [];
        $('#variantRowsContainer .variant-row').each(function () {
            var margin = refreshVariantRowMargin($(this));
            if (margin !== null) {
                margins.push(margin);
            }
        });
        return margins;
    }

    function formatVariantMarginSummary(margins) {
        if (!margins.length) {
            return null;
        }
        var min = Math.min.apply(null, margins);
        var max = Math.max.apply(null, margins);
        if (Math.abs(min - max) < 0.05) {
            return formatMarginPercent(min);
        }
        return formatMarginPercent(min) + ' – ' + formatMarginPercent(max);
    }

    function refreshSanitySummary() {
        if (countVariantRows() > 0) {
            var margins = refreshVariantMargins();
            var marginSummary = formatVariantMarginSummary(margins);
            $('#summarySellPrice').text('حسب التنوع');
            $('#summaryCost').text('—');
            if (marginSummary === null) {
                applyMarginState($('#summaryProfitMargin'), null);
            } else {
                $('#summaryProfitMargin').text(marginSummary);
                var hasNegative = margins.some(function (m) { return m < 0; });
                $('#summaryProfitMargin').toggleClass('is-negative', hasNegative);
                $('#summaryProfitMargin').toggleClass('is-empty', false);
            }
            return;
        }

        $('#summarySellPrice').text(formatPriceDisplay($('#sell_price1').val()));
        $('#summaryCost').text(formatPriceDisplay($('#direct_cost_price').val()));
        refreshProfitMargin();
    }

    function refreshSellPricingVisibility() {
        var optionalSell = !sellIsAlwaysOn(currentType());
        $('#pricing-optional-hint').toggleClass('d-none', !optionalSell);
    }

    function refreshSummary() {
        refreshParentPricingVisibility();
        refreshSellPricingVisibility();
        refreshSanitySummary();
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
        var sellRequired = sellIsAlwaysOn(currentType());
        var hasVariants = countVariantRows() > 0;

        if (sellRequired && !hasVariants) {
            var sellPrice = parseFloat($('#sell_price1').val());
            if (!isFinite(sellPrice) || sellPrice <= 0) {
                return showValidationFailure('يرجى إدخال سعر البيع', ['#sell_price1']);
            }
        }

        var directCost = parseFloat($('#direct_cost_price').val());
        if ($('#direct_cost_price').val() !== '' && (!isFinite(directCost) || directCost < 0)) {
            return showValidationFailure('التكلفة يجب أن تكون صفراً أو أكثر', ['#direct_cost_price']);
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

        $('.item-type-choice').on('click', function () {
            var type = $(this).data('item-type');
            $('.item-type-choice').removeClass('active');
            $(this).addClass('active');
            $('#item_type').val(type);
            refreshSectionStates();
        });

        $('#direct_cost_price, #sell_price1').on('input change', function () {
            refreshSectionStates();
        });

        $('#item-main-form').on('submit', function (event) {
            var $sellBarcode = $('input[name="sell_barcode"]');
            if ($sellBarcode.length) {
                $sellBarcode.val($.trim($('input[name="barcode"]').val() || ''));
            }
            if (countVariantRows() > 0) {
                $('#sell_price1').val('0');
                $('#direct_cost_price').val('');
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

        $(document).on('itemEditorVariantsChanged', function () {
            refreshSummary();
        });

        $(document).on('input change', '.variant-sell-price-input, input[name="variant_cost_price[]"]', function () {
            refreshSummary();
        });

        refreshSectionStates();
        refreshSummary();
    });
})(jQuery);
