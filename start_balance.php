<?php include 'includes/header.php'; ?>
<?php include 'includes/navbar.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">




        <form action="do/doadd_start_balance.php" method="post">
            <div class="card">
                <div class="card-header">
                    <div class="filter">
                        <div class="row">
                            <div class="col-md-4">
                                فلتر
                                <select name="" id="accountFilter" class="form form-control">
                                    <?php
                                    $sqlbasic = "SELECT * FROM acc_head WHERE isdeleted = 0 AND is_basic = 1";
                                    $resbasic = $conn->query($sqlbasic);
                                    while($rowbasic = $resbasic->fetch_assoc()) {
                                    ?>    
                                    <option value="<?= $rowbasic['code'] ?>"><?= $rowbasic['aname'] ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                بحث    
                                <input type="text" id="searchInput" class="form form-control" placeholder="بحث">
                            </div>
                            <div class="col-md-4">
                                <div class="btn bg-yellow-400" id="edit_balance">
                                    <i class="fa fa-pen"></i>
                                    <br>
                                    <p>بدأ تعديل الارصدة الافتتاحية</p>
                                </div>

                                <button class="btn bg-green-400" tybe="submit" name="save_balance">
                                    <i class="fa fa-save"></i>
                                    <br>
                                    <p>حفظ التعديلات</p>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table">
                        <table class="table table-stripped table-hover table-sortable" id="accountsTable">
                            <thead>
                                <tr>
                                    <th>الكود</th>
                                    <th>اسم الحساب</th>
                                    <th>الرصيد الافتتاحي الجديد</th>
                                    <th>قيمة التسوية</th>
                                    <th>الرصيد الافتتاحي السابق</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $sqlacc = "SELECT * FROM acc_head WHERE isdeleted = 0 AND is_basic = 0";
                                $resacc = $conn->query($sqlacc);
                                while($rowacc = $resacc->fetch_assoc()) {
                                ?>
                                    <tr>
                                        <td><?= $rowacc['code']?><input name="acc_id[]" type="text" class="acc_id" value="<?= $rowacc['id']?>" hidden></td>
                                        <td><?= $rowacc['aname']?></td>
                                        <td><input name="newbalance[]" type="number" class="form form-control new-balance font-bold m-0 p-0 <?php if($rowacc['balance'] < 0) {echo "text-red-500";} ?>" value="<?= $rowacc['balance']?>" disabled <?php if($rowacc['editable'] == 0) {echo "readonly";} ?>></td>
                                        <td><input type="text" readonly class="form form-control settle m-0 p-0" value="00.00"></td>
                                        <td class="old-balance <?php if($rowacc['balance'] < 0) {echo "text-red-500";} ?>"><?= $rowacc['balance']?></td>
                                    </tr>
                                <?php }?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer">
                    <div class="row">
                        <div class="col">
                            <b>اجمالي مدين</b>
                            <input type="text" id="total_debit" value="00.00" disabled>
                            <b>اجمالي دائن</b>
                            <input type="text" id="total_credit" value="00.00" disabled>
                            <p>الفرق</p>
                            <input type="text" id="total_diff" value="00.00" disabled>
                        </div>
                        <div class="col">
                            <b>فرق الميزانية</b>
                            <input type="text" id="total_diff_budget" value="00.00" disabled>
                        </div>
                    </div>
                </div>
            </div>
        </form>







        </div>
    </section>
</div>

<?php include 'includes/footer.php'; ?>

<script>
    // Enable editing of balances
    document.getElementById('edit_balance').addEventListener('click', function() {
        const newBalances = document.querySelectorAll('.new-balance');
        newBalances.forEach(function(input) {
            input.disabled = false;
        });
    });

    // Filter by account code
    document.getElementById('accountFilter').addEventListener('change', function() {
        const selectedCode = this.value;
        filterAccounts(selectedCode);
    });

    // Search functionality
    document.getElementById('searchInput').addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        searchAccounts(searchTerm);
    });

    // Update totals dynamically
    function updateTotals() {
        let totalDebit = 0;
        let totalCredit = 0;
        let totalDiff = 0;
        const rows = document.querySelectorAll('tbody tr');
        rows.forEach(function(row) {
            const newBalance = parseFloat(row.querySelector('.new-balance').value) || 0;
            const oldBalance = parseFloat(row.querySelector('.old-balance').textContent) || 0;
            const settle = parseFloat(row.querySelector('.settle').value) || 0;

            totalDebit += Math.max(0, newBalance);
            totalCredit += Math.max(0, oldBalance);
            totalDiff += Math.abs(newBalance - oldBalance);
        });

        document.getElementById('total_debit').value = totalDebit.toFixed(2);
        document.getElementById('total_credit').value = totalCredit.toFixed(2);
        document.getElementById('total_diff').value = totalDiff.toFixed(2);
    }

    // Call updateTotals on input change
    const balanceInputs = document.querySelectorAll('.new-balance');
    balanceInputs.forEach(function(input) {
        input.addEventListener('input', function() {
            // Change color if balance is less than zero
            const newBalance = parseFloat(input.value) || 0;
            if (newBalance < 0) {
                input.classList.add('text-red-500');
            } else {
                input.classList.remove('text-red-500');
            }

            // Update the settle value
            const row = input.closest('tr');
            const oldBalance = parseFloat(row.querySelector('.old-balance').textContent) || 0;
            const settleInput = row.querySelector('.settle');
            settleInput.value = (oldBalance - newBalance).toFixed(2);

            // Update totals after each input change
            updateTotals();
        });
    });

    // Search accounts based on search input
    function searchAccounts(term) {
        const rows = document.querySelectorAll('tbody tr');
        rows.forEach(function(row) {
            const accountName = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
            if (accountName.includes(term)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    // Filter accounts by selected code
    function filterAccounts(code) {
        const rows = document.querySelectorAll('tbody tr');
        rows.forEach(function(row) {
            const accountCode = row.querySelector('td:nth-child(1)').textContent;
            if (accountCode === code) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
    const accIdValue = document.getElementById('acc_id').value;

    // Check if acc_id value is "148"
    if (accIdValue === "148") {
        // Make the inputs readonly for this account
        const newBalances = document.querySelectorAll('.new-balance');
        newBalances.forEach(function(input) {
            input.readOnly = true;  // Make the input readonly
        });

        const settleInputs = document.querySelectorAll('.settle');
        settleInputs.forEach(function(input) {
            input.readOnly = true;  // Make the input readonly
        });
    }

    // Add focusout event listener to new balance and settle inputs
    const balanceInputs = document.querySelectorAll('.new-balance, .settle');
    balanceInputs.forEach(function(input) {
        input.addEventListener('focusout', function() {
            alert('Focus lost on input: ' + input.name);  // Replace input.name with the appropriate label or description if needed
        });
    });
});

</script>
