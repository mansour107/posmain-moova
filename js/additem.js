$(document).ready(function() {
    // Add new row
    $('#addUnit').click(function() {
        // Clone the first row
        var clone = $('.urow').first().clone();

        // Reset specific fields in the cloned row
        clone.find('input[name="u_val[]"]').val('6').prop('readonly', false); // Reset u_val
        clone.find('input[name="unit_barcode[]"]').val(''); // Clear barcode

        // Get the value of the 'u_val' field in the main row (the first row)
        var u_val_main = parseFloat($('.urow').first().find('input[name="u_val[]"]').val()) || 1;

        // Multiply the values in the cloned row by u_val of the first row
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

        // Append the cloned row after the last row
        $('.urow').last().after(clone);

        // Attach delete functionality to the newly added row
        clone.find('.deleteRow').click(function() {
            if ($('.urow').length > 1) clone.remove();
            else alert('لا يمكن حذف الوحدة الاولي');
        });
    });

    // Attach delete functionality to the existing rows
    $('.deleteRow').click(function() {
        if ($('.urow').length > 1) $(this).closest('.urow').remove();
        else alert('لا يمكن حذف الوحدة الاولي');
    });
});
