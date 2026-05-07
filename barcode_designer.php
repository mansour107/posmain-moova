<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barcode Designer</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.0.0/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="js/scripts.js" defer></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-4">
        <h1 class="text-2xl font-bold mb-4">Barcode Designer</h1>
        <input type="text" id="barcode-input" class="border border-gray-300 p-2 mb-4" placeholder="Enter barcode value">
        <div id="design-area" class="border border-gray-300 h-64 relative">
            <!-- Barcode elements will be dynamically added here -->
        </div>
        <button id="save-button" class="mt-4 bg-blue-500 text-white px-4 py-2">Save Barcode</button>
    </div>






<script>
    $(document).ready(function () {
    $('#design-area').sortable({
        placeholder: 'ui-state-highlight',
        start: function (event, ui) {
            ui.placeholder.height(ui.item.height());
        }
    });

    // Add barcode elements (example)
    $('#design-area').append('<div class="barcode-item bg-white border border-gray-500 h-12 w-32 relative">Barcode</div>');

    $('#save-button').on('click', function () {
        const elements = [];
        $('#design-area .barcode-item').each(function () {
            const item = {
                text: $(this).text(),
                height: $(this).height(),
                width: $(this).width(),
                marginTop: $(this).css('margin-top'),
                marginLeft: $(this).css('margin-left'),
                fontSize: $(this).css('font-size'),
            };
            elements.push(item);
        });

        $.post('save.php', { elements: JSON.stringify(elements) }, function (response) {
            alert(response.message);
        }, 'json');
    });
});
</script>
</body>
</html>
