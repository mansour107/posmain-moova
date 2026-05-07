<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <?php
        $rowjournalid = $conn->query("SELECT max(journal_id) FROM journal_heads")->fetch_assoc();
        $jrnlid = ($rowjournalid['max(journal_id)'] == null) ? 1 : $rowjournalid['max(journal_id)'] + 1;
      ?>

      <form action="do/doadd_multijournal.php" method="post">
        <div class="card">
          <div class="card-header">
            <div class="row">
              <div class="col col-3">
                <h1>قيد يومية متعدد</h1>
              </div>
              <div class="col">
                <div class="row">
                  <div class="col-md-2">
                    <div class="form-group">
                      <label for="">رقم دفتري</label>
                      <input name="journal_id" type="text" class="form-control" value="<?= $jrnlid ?>">
                    </div>
                  </div>
                  <div class="col-md-2">
                    <div class="form-group">
                      <label for="">تاريخ</label>
                      <input name="jdate" type="date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <div class="row"><div class="col"><i class="text-zinc-500">تأكد من توازن القيد و عدم ترك قيم فارغة</i></div></div>
          </div>

          <div class="card-body bg-zinc-50">
            <div class="row">
              <div class="col">
                <div class="table-responsive">
                  <table class="table table-bordered table-hover">
                    <thead>
                      <tr>
                        <th class="col-md-3">من</th>
                        <th class="col-md-8">اسم الحساب</th>
                        <tr></tr>
                      </tr>
                    </thead>
                    <tbody id="depitBody">
                      <tr id="depitTr" class="depitTr">
                        <td><input  class="frst form form-control depit" type="text" name="depitval[]" id="depit"></td>
                        <td>
                          <select class="form-control depitacc" name="depitname[]" id="depitacc">
                            <option value="0">اختر حساب</option>
                            <?php
                              $resacc = $conn->query("SELECT * FROM acc_head WHERE is_basic = 0 ");
                              while ($rowacc = $resacc->fetch_assoc()) {
                                echo "<option value='{$rowacc['id']}'>{$rowacc['code']}_{$rowacc['aname']}</option>";
                              }
                            ?>
                          </select>
                        </td>
                      </tr>
                      <tr>
                        <td><button class="btn bg-sky-200" id="addDepit">+</button></td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
              <div class="col">
                <div class="table-responsive">
                  <table class="table table-bordered table-hover">
                    <thead>
                      <tr>
                        <th class="col-3">الي</th>
                        <th class="col-8">اسم الحساب</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr id="creditTr" class="creditTr">
                        <td><input  class="form form-control credit" type="text" name="creditval[]" id="credit"></td>
                        <td>
                          <select class="form-control creditacc" name="creditname[]" id="creditacc" style="height:70px !important">
                            <option value="0">اختر حساب</option>
                            <?php
                              $resacc = $conn->query("SELECT * FROM acc_head WHERE is_basic = 0 " . (isset($_GET['a']) && $_GET['a'] == 1 ? "AND code NOT LIKE '11%'" : (isset($_GET['a']) && $_GET['a'] == 2 ? "AND code LIKE '11%'" : "")));
                              while ($rowacc = $resacc->fetch_assoc()) {
                                echo "<option value='{$rowacc['id']}'>{$rowacc['code']}_{$rowacc['aname']}</option>";
                              }
                            ?>
                          </select>
                        </td>
                      </tr>
                      <tr>
                        <td><button class="btn bg-sky-200" id="addCredit">+</button></td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>

            <div class="row">
              <label for="details">بيان</label>
              <input  type="text" name="details" class="form-control">
            </div>
          </div>

          <div class="card-footer">
            <div class="row">
              <div class="col">
                <div class="form-group">
                  <label for="">اجمالي مدين</label>
                  <input name="total"  id="depit2" type="text" readonly>
                </div>
              </div>
              <div class="col">
                <div class="form-group">
                  <label for="">اجمالي دائن</label>
                  <input  id="credit2" type="text" disabled>
                </div>
              </div>
              <div class="col">
                <div class="form-group">
                  <label for="">الفرق</label>
                  <input  id="balance" type="text" disabled>
                </div>
              </div>
            </div>
            <div class="row">
              <div class="col"><button type="submit" class="btn btn-primary btn-block" id="confirm">حفظ</button></div>
              <div class="col"><button type="reset" class="btn btn-block">مسح</button></div>
            </div>
          </div>
        </div>
      </form>
    </div>
  </section>
</div>























<script>

$(document).ready(function() {
    // Function to calculate the sum of elements with a specific selector
    function sum(elements) {
        let total = 0;
        elements.each(function() {
            // Parse the value as a float and add to the total, defaulting to 0 if empty or NaN
            total += parseFloat($(this).val()) || 0;
        });
        return total;
    }

    // Update sums and balance when any input changes
    $("input").on("keyup", function() {
        let depitSum = sum($(".depit"));
        $("#depit2").val(depitSum);

        let creditSum = sum($(".credit"));
        $("#credit2").val(creditSum);

        let balance = creditSum - depitSum;
        $("#balance").val(balance);
    });

    // Initialize Select2 for account dropdowns
    $('.depitacc, .creditacc').select2();
    $("#confirm").hide();

    // Show the confirm button when all conditions are met
    $("input, select").focusout(function() {
        let balance = parseFloat($("#balance").val()) || 0;
        let depitacc = $("#depit2").val();
        let creditacc = $("#credit2").val();
        $("#confirm").toggle(balance === 0 && depitacc > 0 && creditacc > 0);
    });

    // Add new row for debit accounts when the `+` button is clicked
    $('#addDepit').click(function(e) {
        e.preventDefault();
        let lastDepitTr = $('.depitTr:last');
        let selected = lastDepitTr.find('.depitacc option:selected');
        let depitval = lastDepitTr.find('input').val();

        if (!depitval || parseFloat(depitval) === 0 || selected.val() == 0) {
            alert('الرجاء إدخال قيمة أكبر من الصفر و اختيار الحساب.');
            return;
        }

        // Add new debit row
        lastDepitTr.before(`
            <tr class="depit-row">
                <td><input class="form-control depit" type="text" name="depitval[]" value="${depitval}"></td>
                <td><input class="form-control" type="hidden" name="depitname[]" value="${selected.val()}">${selected.text()}</td>
                <td><button type="button" class="delete-row btn btn-danger">X</button></td>
            </tr>
        `);

        // Clear the last input fields after adding the row
        lastDepitTr.find('input').val('').end().find('.depitacc').prop('selectedIndex', 0);

        // Recalculate sums
        $("input").keyup();
    });

    // Add new row for credit accounts when the `+` button is clicked
    $('#addCredit').click(function(e) {
        e.preventDefault();
        let lastCreditTr = $('.creditTr:last');
        let selected = lastCreditTr.find('.creditacc option:selected');
        let creditval = lastCreditTr.find('input').val();

        if (!creditval || parseFloat(creditval) === 0 || selected.val() == 0) {
            alert('الرجاء إدخال قيمة أكبر من الصفر و اختيار الحساب.');
            return;
        }

        // Add new credit row
        lastCreditTr.before(`
            <tr class="credit-row">
                <td><input class="form-control credit" type="text" name="creditval[]" value="${creditval}"></td>
                <td><input class="form-control" type="hidden" name="creditname[]" value="${selected.val()}">${selected.text()}</td>
                <td><button type="button" class="delete-row btn btn-danger">X</button></td>
            </tr>
        `);

        // Clear the last input fields after adding the row
        lastCreditTr.find('input').val('').end().find('.creditacc').prop('selectedIndex', 0);

        // Recalculate sums
        $("input").keyup();
    });

    // Remove row when the delete button is clicked
    $(document).on('click', '.delete-row', function() {
        $(this).closest('tr').remove();
        $("input").keyup(); // Recalculate sums after deletion
    });
});
</script>

<?php include('includes/footer.php') ?>
