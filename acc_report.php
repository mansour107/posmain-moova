<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<?php
$a01 = "";$b1="";
if (isset($_GET)) {
    if (isset($_GET['acc'])) {  
        if ($_GET['acc'] == "clients") {
            $a01 = " AND code LIKE '122%' ";
            $code = 122;
            $b1 = "?parent_id=122"; 
        }
        if ($_GET['acc'] == "suppliers") {
            $a01 = " AND code LIKE '211%' ";
            $code = 211;
            $b1 = "?parent_id=211";
        }
        if ($_GET['acc'] == "funds") {
            $a01 = " AND code LIKE '121%' ";
            $code = 121;
            $b1 = "?parent_id=121";
        }
        if ($_GET['acc'] == "banks") {
            $a01 = " AND code LIKE '124%' ";
            $code = 124;
            $b1 = "?parent_id=124";
        }
        if ($_GET['acc'] == "expenses") {
            $a01 = " AND code LIKE '44%' ";
            $code = 44;
            $b1 = "?parent_id=44";
        }
        if ($_GET['acc'] == "revenous") {
            $a01 = " AND code LIKE '32%' ";
            $code = 32;
            $b1 = "?parent_id=32";
        }
        if ($_GET['acc'] == "creditors") {
            $a01 = " AND code LIKE '212%' ";
            $code = 212;
            $b1 = "?parent_id=212";
        }
        if ($_GET['acc'] == "depitors") {
            $a01 = " AND code LIKE '125%' ";
            $code = 125;
            $b1 = "?parent_id=125";
        }
        if ($_GET['acc'] == "partners") {
            $a01 = " AND code LIKE '221%' ";
            $code = 221;
            $b1 = "?parent_id=221";
        }
        if ($_GET['acc'] == "assets") {
            $a01 = " AND code LIKE '11%' ";
            $code = 11;
            $b1 = "?parent_id=11";
        }
        if ($_GET['acc'] == "employees") {
            $a01 = " AND code LIKE '213%' ";
            $code = 213;
            $b1 = "?parent_id=213";
        }
        if ($_GET['acc'] == "rentable") {
            $a01 = " AND code LIKE '112%' ";
            $code = 112;
            $b1 = "?parent_id=112";
        }
        if ($_GET['acc'] == "stores") {
            $a01 = " AND code LIKE '123%' ";
            $code = 123;
            $b1 = "?parent_id=123";
        }
}}

?>
<?php
// التأكد من ان balance في acc_head لكل row = 
// اجمالي المدين - اجمالي الدائن في جدول journal_entries where account_id = acc_head.id 
$sqlchk = "UPDATE acc_head SET balance = ( SELECT SUM(journal_entries.debit)- SUM(journal_entries.credit) FROM journal_entries WHERE journal_entries.account_id = acc_head.id AND journal_entries.isdeleted = 0 );";

$conn->query($sqlchk);

// أولاً: نتأكد إن الحسابات الرئيسية is_basic = 1
$main_accounts = ['122', '211', '121', '124', '44', '32', '212', '125', '221', '11', '213', '112', '123'];
foreach ($main_accounts as $acc_code) {
    $fix_main = "UPDATE acc_head SET is_basic = 1 WHERE code = '$acc_code' AND isdeleted = 0";
    $conn->query($fix_main);
}

// ثانياً: إصلاح الحسابات الفرعية لتكون is_basic = 0
$fix_clients = "UPDATE acc_head SET is_basic = 0 WHERE code LIKE '122%' AND code != '122' AND is_basic = 1 AND isdeleted = 0";
$conn->query($fix_clients);

$fix_suppliers = "UPDATE acc_head SET is_basic = 0 WHERE code LIKE '211%' AND code != '211' AND is_basic = 1 AND isdeleted = 0";
$conn->query($fix_suppliers);

$fix_employees = "UPDATE acc_head SET is_basic = 0 WHERE code LIKE '213%' AND code != '213' AND is_basic = 1 AND isdeleted = 0";
$conn->query($fix_employees);

?>
<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
        <div class="card card">
            <div class="card-header">
                <div class="row">
                    <div class="col">
                <h3 class="hazaz"> قائمة الحسابات <?php
                    if (isset($code)) {
                    $rowname = $conn->query("SELECT `aname` FROM acc_head where code = $code")->fetch_assoc();
                     echo " \ ".$rowname['aname'];} ?></h3>
                    </div>

                    <div class="col">
                <a href="add_account.php<?= $b1?>"><div class="btn btn-info float-right hadi-white-flash" id="addNewElement">جديد</div></a>
                    </div>
                </div>
                <div class="row">
                    <div class="col"></div>
                    <div class="col"><input class="form-control form-control-sm frst" type="text" name="" id="itmsearch" placeholder="بحث بالكود | اسم الحساب | id"></div>
                    
                </div>
            </div>

            <div class="card-body">
            <div class="table-responsive">
            <table id="horsTable" class="table table-hover table-stripped " data-page-length='50'>
                <thead>
                   <tr>
                    <th>#</th>
                    <th>الاسم</th>
                    <th>الرصيد</th>
                    <th>العنوان</th>
                    <th>التليفون</th>
                    <th>id</th>
                    <th>عمليات</th>
                   </tr>
                </thead>
                <tbody>
                <?php  
                $sqlacc="SELECT * from acc_head where is_basic = 0  $a01 AND isdeleted = 0 order by aname " ;
                $resacc = $conn->query($sqlacc);
                $x = 0;
                while ($rowacc = $resacc->fetch_assoc()) {
                  $x++
                ?>
                   <tr class="tr1">
                    <td><?= $x ?></td>
                    <td><form action="summary.php" method="post"><input type="text" hidden value="<?= $rowacc['id'] ?>" name="acc_id"><button class="btn btn-light btn-block" tybe="submit"><?= $rowacc['code'] ?>-<?= $rowacc['aname'] ?></button></form></td>
                    <td><?= $rowacc['balance'] ?></td>
                    <td><?= $rowacc['address'] ?></td>
                    <td><?= $rowacc['phone'] ?></td>
                    <td><?= $rowacc['id'] ?></td>
                    <td>
                        <a href="edit_account.php?id=<?= $rowacc['id']?>" class="btn btn-warning"><i class="fa fa-pen text-yellow-50"></i></a>
                        <a href="do/dodel_account.php?id=<?= $rowacc['id']?>" class="btn btn-danger"><i class="fa fa-trash text-yellow-50"></i></a>
                </td>
                   </tr>
              <?php } ?>
                </tbody>
                <tfoot>
                   <tr>
                    <th>#</th>
                    <th>الاسم</th>
                    <th>الرصيد</th>
                    <th>العنوان</th>
                    <th>التليفون</th>
                    <th>id</th>
                    <th>عمليات</th>
                   </tr>
                </tfoot>

            </table>
        </div>
            </div>
        </div>
    </div>
    </section>
</div>

<?php include('includes/footer.php') ?>
