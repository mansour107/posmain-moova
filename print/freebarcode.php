<!DOCTYPE html>
<html lang="ar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>طباعة الباركود</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/smoothness/jquery-ui.css">
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.0/dist/JsBarcode.all.min.js"></script>

    
    <style>
        body {
            font-family: Arial, sans-serif;
            direction: rtl;
            text-align: center;
            padding: 20px;
        }
        .canvas-container {
            position: relative;
            border: 2px dashed #aaa;
            display: inline-block;
            margin-top: 20px;
            background: white;
        }
        .draggable {
            position: absolute;
            cursor: grab;
        }
        .resizable {
            border: 1px solid #000;
            padding: 5px;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .resizable img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .delete-btn {
            position: absolute;
            top: -10px;
            right: -10px;
            background: red;
            color: white;
            border: none;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 14px;
            text-align: center;
            cursor: pointer;
            display: none;
        }
        .resizable:hover .delete-btn {
            display: block;
        }
        .controls {
            margin-bottom: 20px;
        }
        @media print {
            body * {
                visibility: hidden;
            }
            #printArea, #printArea * {
                visibility: visible;
            }
            #printArea {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }
        }
            .print-btn {
            position: fixed;
            bottom: 20px;
            left: 20px;
            }

    </style>
</head>
<body>
    <div class="container">
        <h2 class="mb-4">طباعة الباركود</h2>
        
        <div class="controls">
            <label>عرض الورقة (mm):</label>
            <input type="number" id="paperWidth" class="form-control d-inline-block w-auto">
            <label>ارتفاع الورقة (mm):</label>
            <input type="number" id="paperHeight" class="form-control d-inline-block w-auto">
            <button class="btn btn-primary" onclick="updateCanvasSize()">تحديث الأبعاد</button>
        </div>

        <div>
            <button class="btn btn-success" onclick="addText()">إضافة نص</button>
            <button class="btn btn-info" onclick="addImage()">إضافة صورة</button>
            <button class="btn btn-warning" onclick="addBarcode()">إضافة باركود</button>

        </div>
        
        <div class="controls mt-3">
            <label>عدد النسخ:</label>
            <input type="number" id="copyCount" class="form-control d-inline-block w-auto" min="1" value="1">
        </div>

        <div class="canvas-container mt-4" id="canvasContainer">
            <div id="canvas" class="position-relative bg-light"></div>
        </div>
        
        <button class="btn btn-danger mt-4 print-btn " onclick="printCanvas()">طباعة</button>
    </div>

    <div id="printArea" style="display: none;"></div>

    <script>
        function mmToPx(mm) {
            return mm * 3.78; // تحويل mm إلى px باستخدام 96 dpi
        }

        function setCookie(name, value, days) {
            let expires = "";
            if (days) {
                let date = new Date();
                date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                expires = "; expires=" + date.toUTCString();
            }
            document.cookie = name + "=" + value + "; path=/" + expires;
        }

        function getCookie(name) {
            let nameEQ = name + "=";
            let ca = document.cookie.split(';');
            for (let i = 0; i < ca.length; i++) {
                let c = ca[i].trim();
                if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
            }
            return null;
        }

        function updateCanvasSize() {
            let width = $("#paperWidth").val();
            let height = $("#paperHeight").val();

            setCookie("paperWidth", width, 365);
            setCookie("paperHeight", height, 365);

            $("#canvas").css({ "width": mmToPx(width) + "px", "height": mmToPx(height) + "px" });
        }

        function loadSavedDimensions() {
            let savedWidth = getCookie("paperWidth") || 80;
            let savedHeight = getCookie("paperHeight") || 50;
            let savedCopies = getCookie("copyCount") || 1;

            $("#paperWidth").val(savedWidth);
            $("#paperHeight").val(savedHeight);
            $("#copyCount").val(savedCopies);

            updateCanvasSize();
        }

        function createDeleteButton(element) {
            let deleteBtn = $("<button class='delete-btn'>❌</button>");
            deleteBtn.click(function () {
                element.remove();
            });
            element.append(deleteBtn);
        }

        function addText() {
            let text = prompt("أدخل النص:");
            if (text) {
                let textElement = $(`<div class='draggable resizable bg-white p-1 border'><span>${text}</span></div>`);
                $("#canvas").append(textElement);
                textElement.draggable().resizable({
                    resize: function (event, ui) {
                        $(this).find("span").css("font-size", ui.size.height / 2 + "px");
                    }
                });
                createDeleteButton(textElement);
            }
        }

        function addImage() {
            let input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/*';
            input.onchange = function (event) {
                let file = event.target.files[0];
                let reader = new FileReader();
                reader.onload = function (e) {
                    let imgElement = $(`<div class='draggable resizable'><img src='${e.target.result}'></div>`);
                    $("#canvas").append(imgElement);
                    imgElement.draggable().resizable({
                        aspectRatio: true,
                        resize: function (event, ui) {
                            $(this).find("img").css({
                                width: ui.size.width + "px",
                                height: ui.size.height + "px"
                            });
                        }
                    });
                    createDeleteButton(imgElement);
                };
                reader.readAsDataURL(file);
            };
            input.click();
        }

        function printCanvas() {
            let copies = $("#copyCount").val();
            setCookie("copyCount", copies, 365);
            
            let printArea = $("#printArea");
            printArea.empty();
            for (let i = 0; i < copies; i++) {
                printArea.append($("#canvas").clone());
            }
            printArea.show();
            window.print();
            printArea.hide();
        }

        $(document).ready(function () {
            loadSavedDimensions();
        });


        function addBarcode() {
    let code = prompt("أدخل الكود المراد تحويله إلى باركود:");
    if (code) {
        let width = parseInt(prompt("أدخل عرض الباركود (px):", 200));
        let height = parseInt(prompt("أدخل ارتفاع الباركود (px):", 80));

        let barcodeContainer = $(`
            <div class='draggable resizable bg-white p-1 border' style='width:${width}px; height:${height}px;'>
                <svg width='100%' height='100%'></svg>
            </div>
        `);

        $("#canvas").append(barcodeContainer);

        function generateBarcode() {
            JsBarcode(barcodeContainer.find("svg")[0], code, {
                format: "CODE128",
                displayValue: true,
                fontSize: Math.max(10, height / 5),
                width: width / 150,
                height: height - 20
            });
        }

        generateBarcode();

        barcodeContainer.draggable().resizable({
            resize: function (event, ui) {
                width = ui.size.width;
                height = ui.size.height;
                generateBarcode();
            }
        });

        createDeleteButton(barcodeContainer);
    }
}


    </script>
    
</body>
</html>
