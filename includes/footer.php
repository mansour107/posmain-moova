<script>
  document.addEventListener("DOMContentLoaded", function() {
      // Hide the loader when the document is ready
      var loader = document.querySelector('.loader');
      if (loader) {
        loader.style.display = 'none';
        setTimeout(function() {
          if (loader) loader.style.display = 'none';
        }, 1000); 
      }
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
    $(document).ready(function(){
      $("#itmsearch").on("keyup", function() {
        var value = $(this).val().toLowerCase();
        $("#horsTable .tr1").filter(function() {
          $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
      });
    });  

    $(document).ready(function(){
      $("#search").on("input keyup", function() {
        var value = $.trim($(this).val()).toLowerCase();
        var $rows = $("#horsTable tbody tr");
        if (!$rows.length) {
          return;
        }
        if (value === "") {
          $rows.show();
          return;
        }
        $rows.each(function () {
          var $tr = $(this);
          var extra = ($tr.attr("data-search") || "").toLowerCase();
          var cells = $tr.children("td:not(:last)").text().toLowerCase();
          var haystack = extra + " " + cells;
          $tr.toggle(haystack.indexOf(value) > -1);
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

$(document).ready(function(){
    $("#exportDB").click(function(){
        // Execute AJAX request
        $.ajax({
            url: "do/dobackup.php", // Target page URL
            type: "POST", // Request type
            data: {}, // Request data if required
            success: function(response){
              alert("تم حفظ نسخه احتياطية بنجاح");
            },
            error: function(xhr, status, error){
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

// Check if myTextarea exists before adding event listener
if (document.getElementById("myTextarea")) {
  document.getElementById("myTextarea").addEventListener("keydown", function(event) {
    if (event.key === "Enter") {
      event.preventDefault();
    }
  });
}


</script>

<script>
// Check if searchSide exists before adding event listener
if (document.getElementById('searchSide')) {
  document.getElementById('searchSide').addEventListener('input', function() {
    var searchQuery = this.value.toLowerCase();
    var listItems = document.querySelectorAll('.nav-item');

    listItems.forEach(function(item) {
      if (item) {
        var text = item.textContent.toLowerCase();
        if (text.includes(searchQuery)) {
          item.style.display = 'block';
        } else {
          item.style.display = 'none';
        }
      }
    });
  });
}

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
// Check if passwordForm exists before adding event listener
if (document.getElementById("passwordForm")) {
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
}




function dis() {
    // التحقق من الكميات قبل إخفاء الأزرار
    const qtyInputs = document.querySelectorAll('.itmqty');
    let hasZeroQty = false;
    
    qtyInputs.forEach(function(input) {
        const qty = parseFloat(input.value) || 0;
        if (qty <= 0) {
            hasZeroQty = true;
        }
    });
    
    // لا تخفي الأزرار إذا كانت هناك كمية صفر
    if (hasZeroQty) {
        alert("لا يمكن أن تكون الكمية صفر أو أقل");
        return false;
    }
    
    // التحقق من قيمة الفاتورة (فقط إذا كان الحقل موجود)
    const headtotalElement = document.getElementById('headtotal');
    if (headtotalElement) {
        const headtotal = parseFloat(headtotalElement.value) || 0;
        if (headtotal <= 0) {
            alert("يجب أن تكون قيمة الفاتورة أكبر من صفر");
            return false;
        }
    }
    
    // إخفاء الأزرار فقط إذا كانت البيانات صحيحة
    const elements = document.getElementsByClassName("dis");
    for (let i = 0; i < elements.length; i++) {
        elements[i].disabled = true;
        elements[i].style.opacity = '0.5';
    }
    
    // السماح بالـ submit
    return true;
}

function checkTotal() {
    // دالة مساعدة - ترجع true دائماً
    return true;
}

// التحقق عند تحميل الصفحة إذا كان تم الإرسال
$(document).ready(function() {
    // تحديث المدفوع عند تغيير الإجمالي
    $('#headnet, #headdisc, #headplus').on('input change', function() {
        const headnet = parseFloat($('#headnet').val()) || 0;
        $('#paid').val(headnet.toFixed(2));
    });
    
    // معالجة الأزرار بشكل صريح - تعمل مع أي form
    $('button[type="submit"][name="submit"]').on('click', function(e) {
        const buttonValue = $(this).val();
        const form = $(this).closest('form');
        
        console.log('Button clicked with value:', buttonValue);
        console.log('Form ID:', form.attr('id'));
        
        // إزالة أي hidden inputs قديمة
        form.find('input[type="hidden"][name="submit"]').remove();
        
        // إضافة hidden input جديد بقيمة الزر
        form.append('<input type="hidden" name="submit" value="' + buttonValue + '">');
        
        console.log('Hidden input added with value:', buttonValue);
    });
    
    // معالجة submit للـ forms
    $('form').on('submit', function(e) {
        const submitter = e.originalEvent?.submitter;
        if (submitter && submitter.name === 'submit') {
            console.log('Submit button clicked:', submitter.name, '=', submitter.value);
            
            // التأكد من وجود hidden input بنفس القيمة
            const form = $(this);
            form.find('input[type="hidden"][name="submit"]').remove();
            form.append('<input type="hidden" name="submit" value="' + submitter.value + '">');
        }
        
        // التحقق من الكميات
        const qtyInputs = document.querySelectorAll('.itmqty');
        let hasZeroQty = false;
        
        qtyInputs.forEach(function(input) {
            const qty = parseFloat(input.value) || 0;
            if (qty <= 0) {
                hasZeroQty = true;
            }
        });
        
        if (hasZeroQty) {
            e.preventDefault();
            alert("لا يمكن أن تكون الكمية صفر أو أقل");
            return false;
        }
        
        // التحقق من قيمة الفاتورة (فقط إذا كان الحقل موجود)
        const headtotalElement = document.getElementById('headtotal');
        if (headtotalElement) {
            const headtotal = parseFloat(headtotalElement.value) || 0;
            if (headtotal <= 0) {
                e.preventDefault();
                alert("يجب أن تكون قيمة الفاتورة أكبر من صفر");
                return false;
            }
        }
        
        // إخفاء الأزرار
        $('.dis').prop('disabled', true).css('opacity', '0.5');
        
        // السماح بالـ submit
        return true;
    });
});

// إزالة event listener المكرر
// $('.dis').click(function(event) {
//     dis();
// });
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
  $(function () {
    $("#myTable").DataTable({
      "responsive": true, "lengthChange": false, "autoWidth": false,
      "buttons": ["copy",  "excel", "pdf", "print", "colvis"]
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

<script>console.log('fooer done __________________>>')</script>
