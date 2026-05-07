<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<div class="content-wrapper">
    <section class="content-header">
        <div class="container mx-auto px-4">
            <div class="max-w-3xl mx-auto">
                <div class="bg-white shadow-md rounded-lg">
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="w-full">
                            <h1>تسجيل حضور بالباركود</h1>
                            <form id="attendanceForm" action="" method="post" onsubmit="return false;">

                            <input name="employee" type="text" class="form-control frst bg-red-300 focus:bg-orange-300">
                            <input type="text" name="fptybe" value="1" hidden>
                            <input type="text" name="fpdate" value="" hidden>
                            <input type="text" name="fptime" value="" hidden>
                            </form>


                            <div class="bg-orange-200 text-orang-700 text-xl min-h-50" id="message"></div>
                            <script>
$(document).ready(function() {
    $('.frst').keypress(function(event) {
        if (event.which === 13) {  // 13 is the Enter key code
            event.preventDefault();
            submitForm();
        }
    });

    function submitForm() {
        $.ajax({
            url: 'js/ajax/scan_fb.php',
            type: 'POST',
            data: $('#attendanceForm').serialize(),
            success: function(response) {
                $('#message').html(response);
                $('.frst').val('');  // Clear the input field after submission
            }
        });
    }
});
</script>






                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>











      </div>
    </section>
</div>





<?php include('includes/footer.php') ?>