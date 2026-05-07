<script>
    document.addEventListener("DOMContentLoaded", function() {
        // Hide the loader when the document is ready
        var loader = document.querySelector('.loader');
        loader.style.display = 'none';
        setTimeout(function() {
            loader.style.display = 'none';
        }, 1000);

    });
    $('#draw').keyup(function() {
        $measure = $('#measure').val();
        $draw = $('#draw').val();
        $fa = $draw / $measure;
        $('#farkh').val(Math.ceil($fa));
    });

    $('#measure').keyup(function() {
        $measure = $('#measure').val();
        $draw = $('#draw').val();
        $fa = $draw / $measure;
        $('#farkh').val(Math.ceil($fa));
    })

    document.querySelectorAll('input').forEach(input => {
        input.addEventListener('focus', function() {
            this.select();
        });
    });
</script>

<script>
    $(document).ready(function() {
        $("#validate_form").parsley();
    })
</script>



<script>
    $(document).ready(function() {
        $("#itmsearch").on("keyup", function() {
            var value = $(this).val().toLowerCase();
            $("#horsTable .tr1").filter(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
            });
        });
    });

    $(document).ready(function() {
        $("#search").on("keyup", function() {
            var value = $(this).val().toLowerCase();
            $("#horsTable tr").filter(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
            });
        });
    });
</script>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        const inputFields = document.querySelectorAll('.nozero');

        inputFields.forEach(function(inputField) {
            inputField.addEventListener('blur', function() {
                if (inputField.value.trim() === '') {
                    inputField.value = '0';
                }
            });
        });
    });
</script>
<script>
    function sT(inputElement) {
        inputElement.select();
    }
</script>



<script>
    $(document).ready(function() {
        $("#exportDB").click(function() {
            // Execute AJAX request
            $.ajax({
                url: "do/dobackup.php", // Target page URL
                type: "POST", // Request type
                data: {}, // Request data if required
                success: function(response) {
                    alert("تم حفظ نسخه احتياطية بنجاح");
                },
                error: function(xhr, status, error) {
                    alert("هناك خطأ ما");
                }
            });
        });
    });
</script>

<!--_________________________________________________________الاحداث __________________________________________-->


<script>
    $(document).ready(function() {

        var firstFrstElement = document.querySelector(".frst");

        if (firstFrstElement) {
            // تركيز المتصفح على العنصر الأول بالفئة "frst"
            firstFrstElement.focus();
        }
    });

    document.addEventListener("keydown", function(event) {
        // التأكد من أن الزر المضغوط هو F6 (القيمة 117)
        if (event.keyCode === 117) {
            event.preventDefault();

            // الحصول على أول عنصر بالفئة "mid"
            var firstFrstElement = document.querySelector(".mid");

            if (firstFrstElement) {
                // تركيز المتصفح على العنصر الأول بالفئة "mid"
                firstFrstElement.focus();
            }
        }
    });


    document.addEventListener("keydown", function(event) {
        // التأكد من أن الزر المضغوط هو F7 (القيمة 118)
        if (event.keyCode === 118) {
            event.preventDefault();

            // الحصول على أول عنصر بالفئة "frst"
            var firstFrstElement = document.querySelector(".last");

            if (firstFrstElement) {
                // تركيز المتصفح على العنصر الأول بالفئة "last"
                firstFrstElement.focus();
            }
        }
    });



    document.addEventListener("keydown", function(event) {
        // التأكد من أن الزر المضغوط هو F1 (القيمة 112)
        if (event.keyCode === 112) {
            event.preventDefault();

            // الحصول على أول عنصر بالفئة "frst"
            var firstFrstElement = document.querySelector(".frst");

            if (firstFrstElement) {
                // تركيز المتصفح على العنصر الأول بالفئة "frst"
                firstFrstElement.focus();
            }
        }
    });


    document.addEventListener("keydown", function(event) {
        // التأكد من أن الزر المضغوط هو F1 (القيمة 112)
        if (event.keyCode === 113) {
            event.preventDefault();

            // الحصول على أول عنصر بالفئة "frst"
            var firstscndElement = document.querySelector(".scnd");

            if (firstscndElement) {
                // تركيز المتصفح على العنصر الأول بالفئة "frst"
                firstscndElement.focus();
            }
        }
    });

    document.addEventListener("keydown", function(event) {
        // التأكد من أن الزر المضغوط هو F3 (القيمة 114)
        if (event.keyCode === 114) {
            event.preventDefault();

            // الحصول على العنصر بالمعرف "addNewElement"
            var addNewElement = document.getElementById("addNewElement");

            if (addNewElement) {
                // إذا تم العثور على العنصر، فقم بتشغيل الحدث النقر عليه
                addNewElement.click();
            }
        }
    });



    $(document).keydown(function(event) {
        // Check if Ctrl+O is pressed
        if (event.ctrlKey && event.key === 'o') {
            event.preventDefault();
            window.location.href = "sales.php?q=sale"; // Replace "https://example.com" with your desired URL
        }
    });


    $(document).keydown(function(event) {

        if (event.ctrlKey && event.key === 'l') {
            event.preventDefault();
            window.location.href = "sales.php?q=buy";
        }
    });

    document.getElementById("myTextarea").addEventListener("keydown", function(event) {
        if (event.key === "Enter") {
            event.preventDefault();
        }
    });
</script>

<script>
    document.getElementById('searchSide').addEventListener('input', function() {
        var searchQuery = this.value.toLowerCase();
        var listItems = document.querySelectorAll('.nav-item');

        listItems.forEach(function(item) {
            var text = item.textContent.toLowerCase();
            if (text.includes(searchQuery)) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
    });

    document.addEventListener('DOMContentLoaded', function() {
        document.addEventListener('keyup', function(event) {
            if (event.target.tagName.toLowerCase() === 'input' && event.target.type === 'text') {
                var input = event.target.value;
                if (input.includes(';')) {
                    event.target.value = input.replace(/;/g, '');
                    alert("Semicolons are not allowed.");
                }
            }
        });
    });
</script>


<script>
    document.getElementById("passwordForm").addEventListener("submit", function(event) {
        event.preventDefault(); // Prevent the form from submitting

        var enteredPassword = document.getElementById("password").value;

        var storedPassword = "<?= $rowstg['edit_pass'] ?>"; // Make sure it's properly escaped

        if (enteredPassword === storedPassword) {
            alert("Passwords match!");

        } else {
            alert("Passwords do not match!");
        }
    });




    function dis() {
        // Corrected function to hide all elements with class "dis"
        const elements = document.getElementsByClassName("dis");
        for (let i = 0; i < elements.length; i++) {
            elements[i].hidden = true;
        }
    }

    $('.dis').click(function(event) {
        // Call dis function on click
        dis();
    });
</script>

<div class="footer">

</div>

<!-- Control Sidebar -->
<aside class="control-sidebar control-sidebar-dark">
    <!-- Control sidebar content goes here -->
</aside>
<!-- /.control-sidebar -->
</div>
<!-- ./wrapper -->
<script src="js/sheetjs/excel.js"></script>

<!-- jQuery -->
<script src="plugins/jquery/jquery.min.js"></script>
<!-- jQuery UI 1.11.4 -->
<script src="plugins/jquery-ui/jquery-ui.min.js"></script>
<!-- Resolve conflict in jQuery UI tooltip with Bootstrap tooltip -->
<script>
    $.widget.bridge('uibutton', $.ui.button)
</script>
<!-- Bootstrap 4 rtl -->
<script src="plugins/bootstrap/bootstrab.rtlcss.min.js"></script>
<!-- Bootstrap 4 -->
<script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<!-- ChartJS -->
<script src="plugins/chart.js/Chart.min.js"></script>
<!-- Sparkline -->
<script src="plugins/sparklines/sparkline.js"></script>
<!-- JQVMap -->
<script src="plugins/jqvmap/jquery.vmap.min.js"></script>
<script src="plugins/jqvmap/maps/jquery.vmap.world.js"></script>
<!-- jQuery Knob Chart -->
<script src="plugins/jquery-knob/jquery.knob.min.js"></script>
<!-- daterangepicker -->
<script src="plugins/moment/moment.min.js"></script>
<script src="plugins/daterangepicker/daterangepicker.js"></script>
<!-- Tempusdominus Bootstrap 4 -->
<script src="plugins/tempusdominus-bootstrap-4/js/tempusdominus-bootstrap-4.min.js"></script>
<!-- Summernote -->
<script src="plugins/summernote/summernote-bs4.min.js"></script>
<!-- overlayScrollbars -->
<script src="plugins/overlayScrollbars/js/jquery.overlayScrollbars.min.js"></script>
<!-- AdminLTE App -->
<script src="dist/js/adminlte.js"></script>
<!-- AdminLTE dashboard demo (This is only for demo purposes) -->
<script src="dist/js/pages/dashboard.js"></script>
<!-- AdminLTE for demo purposes -->
<script src="dist/js/demo.js"></script>

<script src="dist/js/parsley.js"></script>
<script src="plugins/datatables/jquery.dataTables.js"></script>
<script src="plugins/datatables-bs4/js/dataTables.bootstrap4.js"></script>

<script src="plugins/toastr/toastr.min.js"></script>

<script src="plugins/sweetalert2/sweetalert2.min.js"></script>

<script src="plugins/select2/js/select2.full.min.js"></script>
<script src="plugins/bootstrap4-duallistbox/jquery.bootstrap-duallistbox.min.js"></script>
<script src="plugins/inputmask/jquery.inputmask.bundle.js"></script>
<script src="plugins/datatables/jquery.dataTables.js"></script>
<script src="plugins/datatables-bs4/js/dataTables.bootstrap4.js"></script>

<script src="plugins/datatables/jquery.dataTables.min.js"></script>
<script src="plugins/datatables-bs4/js/dataTables.bootstrap4.min.js"></script>
<script src="plugins/datatables-responsive/js/dataTables.responsive.min.js"></script>
<script src="plugins/datatables-responsive/js/responsive.bootstrap4.min.js"></script>
<script src="plugins/datatables-buttons/js/dataTables.buttons.min.js"></script>
<script src="plugins/datatables-buttons/js/buttons.bootstrap4.min.js"></script>
<script src="plugins/jszip/jszip.min.js"></script>
<script src="plugins/pdfmake/pdfmake.min.js"></script>
<script src="plugins/pdfmake/vfs_fonts.js"></script>
<script src="plugins/datatables-buttons/js/buttons.html5.min.js"></script>
<script src="plugins/datatables-buttons/js/buttons.print.min.js"></script>
<script src="plugins/datatables-buttons/js/buttons.colVis.min.js"></script>


<script>
    $(function() {
        $("#myTable").DataTable({
            "responsive": true,
            "lengthChange": false,
            "autoWidth": false,
            "buttons": ["copy", "excel", "pdf", "print", "colvis"]
        }).buttons().container().appendTo('#myTable_wrapper .col-md-6:eq(0)');
        $('#example2').DataTable({
            "paging": true,
            "lengthChange": false,
            "searching": false,
            "ordering": true,
            "info": true,
            "autoWidth": false,
            "responsive": true,
        });
    });
</script>


</body>

</html>

<script>
    console.log('fooer done __________________>>')
</script>
