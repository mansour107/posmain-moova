$(document).ready(function() {
    // Select2 is already initialized in sales.js
 
    // Form submission with AJAX - optimized
    $('#addItemForm').off('submit').on('submit', function(event) {
        event.preventDefault(); 
        
        const $btn = $('#addItemBtn');
        if ($btn.prop('disabled')) return;
        
        $btn.prop('disabled', true).hide();
        
        $.ajax({
            url: 'js/ajax/doadd_item.php',
            type: 'POST',
            data: new FormData(this),
            contentType: false,
            processData: false,
            success: function(response) {
                $('#msgitem').html(response);
                const $codenew = parseInt($('#code').val(), 10) + 1; 
                $('#code, #barcode, #unitCode').val($codenew);
                refreshSelect();
            },
            error: function() {
                console.error('Error submitting form');
            },
            complete: function() {
                setTimeout(() => $btn.prop('disabled', false).show(), 2500);
            }
        });
    });

    // Refresh the Select2 element - optimized
    function refreshSelect() {
        $.get('js/ajax/refresh_select.php', function(data) {
            $('#mySelectitm').html(data);
        });
    }

    // Add new unit row - optimized
    $('#addUnit').off('click').on('click', function(){
        const $clone = $('.urow').first().clone();
        $clone.find('input[type="number"]').prop('readonly', false).val('6'); 
        $clone.find('input[type="text"]').val('');
        $('.urow').last().after($clone); 
        
        $clone.find('.deleteRow').off('click').on('click', function(){
            if ($('.urow').length > 1) {
                $(this).closest('.urow').remove(); 
            } else {
                alert('لا يمكن حذف الوحدة الأولي');
            }
        });
    });

    // Form validation - إزالة لأن dis() بتعمل كل حاجة
    // $('#submit, #submit2').off('click').on('click', function(event) {
    //     // dis() هتتعامل مع كل الـ validation
    // });

    // Enter key on itmval - optimized
    $(document).off('keydown', '.itmval').on('keydown', '.itmval', function(event) {
        if (event.key === "Enter") {
            event.preventDefault();
            $('#addRow').click();
            setTimeout(() => {
                $('#mySelectitm').select2('open');
                $('.select2-search__field').focus();
            }, 100);
        }
    });

    // Show operations toggle
    $('#showOps').off('click').on('click', function() {
        $('#operations').toggle();
    });
});