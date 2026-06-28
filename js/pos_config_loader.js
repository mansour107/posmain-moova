/**
 * POS Configuration Loader
 * تحميل إعدادات نظام نقاط البيع
 */

console.log('🚀 POS System JS Loading');

// Global error handler
window.addEventListener('error', function(e) {
    console.error('Global JavaScript Error:', e.error);
    console.error('Error in file:', e.filename, 'at line:', e.lineno);
});

// Load POS Configuration
let posConfig = null;

$.ajax({
    url: 'pos_config.json',
    type: 'GET',
    dataType: 'json',
    async: false,
    success: function(config) {
        posConfig = config;
        console.log('✅ POS Config loaded:', config);
    },
    error: function() {
        console.error('❌ Failed to load POS config, using defaults');
        // Default config
        posConfig = {
            scale_barcode: {
                enabled: true,
                prefix: "200",
                barcode_length: 13,
                item_code_start: 3,
                item_code_length: 4,
                weight_start: 7,
                weight_length: 5,
                weight_divisor: 1000
            }
        };
    }
});

// Function to update table status
function updateTableStatus(tableId, isOccupied) {
    $.ajax({
        url: 'ajax/update_table_status.php',
        type: 'POST',
        data: {
            table_id: tableId,
            is_occupied: isOccupied ? 1 : 0
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                console.log('✅ Table status updated');
            } else {
                console.error('❌ Failed to update table status:', response.message);
            }
        },
        error: function(xhr, status, error) {
            console.error('❌ AJAX error updating table status:', error);
        }
    });
}

