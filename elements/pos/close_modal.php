<!-- Button trigger modal -->
<button type="button" class="btn btn-light" id="confirmClose" data-bs-toggle="modal" data-bs-target="#closed">اغلاق الشيفت</button>

<!-- Modal -->
<div class="modal fade" id="closed" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h1 class="modal-title fs-5" id="exampleModalLabel">هل تريد بالتأكيد انهاء مبيعات الشيفت؟</h1>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form action="do/doadd_close.php" method="post">
        <div class="modal-body">
          <b>اجمالي المبيعات</b>
          <input id="total_sales" readonly class="bg-slate-100 text-center" type="text" name="total_sales" value="<?php 
         $userid = $_SESSION['userid'];
         $row_net = $conn->query("SELECT SUM(fat_net) AS fatnet FROM `ot_head` WHERE user = '$userid' AND (pro_tybe = 3 OR pro_tybe = 9) AND closed = 0")->fetch_assoc();
         
         if ($row_net['fatnet'] === null) {
             echo 0;
         } else {
             echo $row_net['fatnet'];
         }         
          ?>">

          <input hidden type="text" name="user" value="<?= $_SESSION['login'] ?>">
          <input hidden type="text" name="date" value="<?= $today ?>">

          <br>
          <div class="row">
            <div class="col">
              <p>من</p>
              <input readonly type="text" name="strttime" class="form-control" value="<?php
              $userid = $_SESSION['userid'];
              $stmt = $conn->prepare("SELECT crtime FROM ot_head WHERE (pro_tybe = 3 OR pro_tybe = 9) AND closed = 0 AND user = ? LIMIT 1");
              $stmt->bind_param("i", $userid);
              $stmt->execute();
              $result = $stmt->get_result();
              $rowstrt = $result->fetch_assoc();
              
              if (!$rowstrt || $rowstrt['crtime'] == null) {
                  echo date("Y-m-d H:i:s");
              } else {
                  echo $rowstrt['crtime'];
              }
              
              ?>">
            </div>
            <div class="col">
              <p>إلى</p>
              <input readonly type="text" name="endtime" class="form-control" value="<?php
                $now = date("Y-m-d H:i:s");
                echo $now;
              ?>">
            </div>
          </div>

          <div class="form-group mt-2">
            <label for="">ح الدرج قبل</label>
            <input id="fund_before" readonly class="form-control" type="text" name="fund_before" value="<?php
              $resfund = $conn->query("SELECT fund_after FROM closed_orders ORDER BY id DESC LIMIT 1");
              $rowfund = $resfund->fetch_assoc();
              echo $rowfund ? $rowfund['fund_after'] : "0.00";
            ?>">
          </div>

          <div class="row">
            <b class="col">مصاريف</b>
            <input id="expenses" class="col form-control" type="number" step="0.01" name="expenses" value="0.00">
          </div>

          <div class="form-group mt-2">
            <label for="">بيان المصاريف</label>
            <input id="exp_notes" class="form-control" type="text" name="exp_notes" value="" placeholder="مثل(300:خامات , 400:فواتير)">
          </div>

          <div class="form-group mt-2">
            <label for="">تسليم الدرج</label>
            <input id="cash" class="form-control" type="number" step="0.01" name="cash" value="0.00">
          </div>

          <div class="form-group mt-2">
            <label for="">الباقي في الدرج</label>
            <input id="fund_after" readonly class="form-control" type="text" name="fund_after" value="0.00">
          </div>

          <div class="form-group mt-2">
            <label for="">ملاحظات</label>
            <input class="form-control" type="text" name="info" value="">
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">اغلاق</button>
          <button type="submit" class="btn btn-primary">اقفال</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
$(document).ready(function () {
    // لحساب الباقي في الدرج تلقائيًا
    function calculateFundAfter() {
        let fundBefore = parseFloat($('#fund_before').val()) || 0;
        let total = parseFloat($('#total_sales').val()) || 0;
        let expenses = parseFloat($('#expenses').val()) || 0;
        let cash = parseFloat($('#cash').val()) || 0;

        let remaining = fundBefore + total - expenses - cash;
        $('#fund_after').val(remaining.toFixed(2));
    }

    // حساب تلقائي عند التغيير
    $('#expenses, #cash').on('input', calculateFundAfter);
    
    // حساب أولي عند فتح المودال
    $('#closed').on('shown.bs.modal', function () {
        calculateFundAfter();
    });
});
</script>


