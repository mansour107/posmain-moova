$(document).ready(function() {
    function usedUnitValues() {
        var values = [];
        $('select[name="unit_id[]"]').each(function() {
            values.push($(this).val());
        });
        return values;
    }

    function chooseFirstUnusedUnit(select) {
        var used = usedUnitValues();
        var selected = select.val();
        select.find('option').each(function() {
            var value = $(this).attr('value');
            if (used.indexOf(value) === -1) {
                selected = value;
                return false;
            }
        });
        select.val(selected);
    }

    function refreshUnitsUi() {
        if (typeof window.refreshItemUnitsUi === 'function') {
            window.refreshItemUnitsUi();
        }
    }

    $('#addUnit').on('click', function() {
        if (!$('.urow').length) {
            return;
        }

        var clone = $('.urow').first().clone();

        clone.find('input[name="u_val[]"]').val('6').prop('readonly', false);
        clone.find('input[name="unit_barcode[]"]').val('');
        chooseFirstUnusedUnit(clone.find('select[name="unit_id[]"]'));

        var u_val_main = parseFloat($('.urow').first().find('input[name="u_val[]"]').val()) || 1;

        clone.find('input[name="cost_price[]"]').val(function() {
            return (parseFloat($('.urow').first().find('input[name="cost_price[]"]').val()) * u_val_main).toFixed(3);
        });

        clone.find('input[name="price1[]"]').val(function() {
            return (parseFloat($('.urow').first().find('input[name="price1[]"]').val()) * u_val_main).toFixed(3);
        });

        clone.find('input[name="price2[]"]').val(function() {
            return (parseFloat($('.urow').first().find('input[name="price2[]"]').val()) * u_val_main).toFixed(3);
        });

        clone.find('input[name="market_price[]"]').val(function() {
            return (parseFloat($('.urow').first().find('input[name="market_price[]"]').val()) * u_val_main).toFixed(3);
        });

        $('.urow').last().after(clone);
        refreshUnitsUi();
    });

    $(document).on('click', '.deleteRow', function() {
        if ($('.urow').length > 1) $(this).closest('.urow').remove();
        else alert('لا يمكن حذف الوحدة الاولي');
        refreshUnitsUi();
    });
});
