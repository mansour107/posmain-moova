<div class="dashboard-widgets">
    <div class="row g-4">
        <?php if($role['sid_accounts'] == 1){ ?>
        <!-- آخر حسابات تم إنشاءها -->
        <div class="col-xl-4 col-lg-6">
            <div class="modern-card card-accounts">
                <div class="card-header">
                    <div class="header-content">
                        <div class="icon-wrapper">
                            <i class="fas fa-chart-line header-icon"></i>
                        </div>
                        <div class="header-text">
                            <h4>آخر حسابات تم إنشاءها</h4>
                            <p class="card-subtitle">أحدث 5 حسابات مضافة</p>
                        </div>
                    </div>
                    <div class="header-badge">
                        <span>5</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-wrapper">
                        <div class="modern-table">
                            <div class="table-header">
                                <div class="table-row header-row">
                                    <div class="table-cell">#</div>
                                    <div class="table-cell">اسم الحساب</div>
                                    <div class="table-cell">الرصيد</div>
                                    <div class="table-cell">يتبع ل</div>
                                </div>
                            </div>
                            <div class="table-body">
                                <?php
                                $resacc = $conn->query("SELECT * FROM acc_head order by id desc limit 5");
                                $x = 0;
                                while ($rowacc = $resacc->fetch_assoc()) {
                                    $x++;
                                ?>
                                <div class="table-row">
                                    <div class="table-cell">
                                        <span class="serial-number"><?= $x ?></span>
                                    </div>
                                    <div class="table-cell">
                                        <div class="account-info">
                                            <div class="main-text"><?= $rowacc['aname'] ?></div>
                                            <div class="sub-text"><?= $rowacc['code'] ?></div>
                                        </div>
                                    </div>
                                    <div class="table-cell">
                                        <div class="amount-display <?= $rowacc['balance'] >= 0 ? 'positive' : 'negative' ?>">
                                            <?= number_format($rowacc['balance'], 2) ?>
                                        </div>
                                    </div>
                                    <div class="table-cell">
                                        <?php 
                                        $p = $rowacc['parent_id']; 
                                        if ($p > 0) {
                                            $parent_result = $conn->query("SELECT aname FROM acc_head WHERE id = $p");
                                            $parent_data = $parent_result ? $parent_result->fetch_assoc() : null;
                                            $pname = $parent_data ? $parent_data['aname'] : '-';
                                        } else {
                                            $pname = '-';
                                        }
                                        echo '<span class="parent-name">' . $pname . '</span>';
                                        ?>
                                    </div>
                                </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <a href="acc_report.php" class="view-all-btn">
                        <span>عرض جميع الحسابات</span>
                        <i class="fas fa-arrow-left"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php } ?>

        <?php if($role['sid_accounts'] == 1){ ?>
        <!-- محلل العمل اليومي -->
        <div class="col-xl-4 col-lg-6">
            <div class="modern-card card-operations">
                <div class="card-header">
                    <div class="header-content">
                        <div class="icon-wrapper">
                            <i class="fas fa-chart-bar header-icon"></i>
                        </div>
                        <div class="header-text">
                            <h4>محلل العمل اليومي</h4>
                            <p class="card-subtitle">أحدث 5 عمليات</p>
                        </div>
                    </div>
                    <div class="header-badge">
                        <span>5</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-wrapper">
                        <div class="modern-table">
                            <div class="table-header">
                                <div class="table-row header-row">
                                    <div class="table-cell">#</div>
                                    <div class="table-cell">التاريخ</div>
                                    <div class="table-cell">العملية</div>
                                    <div class="table-cell">القيمة</div>
                                </div>
                            </div>
                            <div class="table-body">
                                <?php
                                $x = 0;
                                $resop = $conn->query("SELECT * FROM ot_head where isdeleted = 0 order by id desc limit 5");
                                while ($rowop = $resop->fetch_assoc()) {
                                    $x++;
                                ?>
                                <div class="table-row">
                                    <div class="table-cell">
                                        <span class="serial-number"><?= $x ?></span>
                                    </div>
                                    <div class="table-cell">
                                        <div class="date-display"><?= $rowop['pro_date'] ?></div>
                                    </div>
                                    <div class="table-cell">
                                        <?php 
                                        $tybe = $rowop['pro_tybe'];
                                        $rowtybe = $conn->query("SELECT pname from pro_tybes where id = $tybe")->fetch_assoc();
                                        echo '<span class="operation-badge">' . $rowtybe['pname'] . '</span>';
                                        ?>
                                    </div>
                                    <div class="table-cell">
                                        <div class="amount-display positive">
                                            <?= number_format($rowop['pro_value'], 2) ?>
                                        </div>
                                    </div>
                                </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <a href="operations_summary.php" class="view-all-btn">
                        <span>عرض جميع العمليات</span>
                        <i class="fas fa-arrow-left"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php } ?>

        <?php if($role['sid_stock'] == 1){ ?>
        <!-- آخر أصناف تم إنشاءها -->
        <div class="col-xl-4 col-lg-6">
            <div class="modern-card card-items">
                <div class="card-header">
                    <div class="header-content">
                        <div class="icon-wrapper">
                            <i class="fas fa-boxes header-icon"></i>
                        </div>
                        <div class="header-text">
                            <h4>آخر أصناف تم إنشاءها</h4>
                            <p class="card-subtitle">أحدث 5 أصناف مضافة</p>
                        </div>
                    </div>
                    <div class="header-badge">
                        <span>5</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-wrapper">
                        <div class="modern-table">
                            <div class="table-header">
                                <div class="table-row header-row">
                                    <div class="table-cell">#</div>
                                    <div class="table-cell">اسم الصنف</div>
                                    <div class="table-cell">الرصيد</div>
                                </div>
                            </div>
                            <div class="table-body">
                                <?php
                                $resitm = $conn->query("SELECT * FROM myitems order by id desc limit 5");
                                $x = 0;
                                while ($rowitm = $resitm->fetch_assoc()) {
                                    $x++;
                                ?>
                                <div class="table-row">
                                    <div class="table-cell">
                                        <span class="serial-number"><?= $x ?></span>
                                    </div>
                                    <div class="table-cell">
                                        <div class="item-info">
                                            <div class="main-text"><?= $rowitm['iname'] ?></div>
                                            <div class="sub-text">رقم: <?= $rowitm['id'] ?></div>
                                        </div>
                                    </div>
                                    <div class="table-cell">
                                        <div class="stock-indicator <?= $rowitm['itmqty'] > 0 ? 'in-stock' : 'out-of-stock' ?>">
                                            <span class="stock-value"><?= $rowitm['itmqty'] ?></span>
                                            <span class="stock-label">وحدة</span>
                                        </div>
                                    </div>
                                </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <a href="myitems.php" class="view-all-btn">
                        <span>عرض جميع الأصناف</span>
                        <i class="fas fa-arrow-left"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php } ?>

        <!-- آخر 5 زيارات -->
        <div class="col-xl-4 col-lg-6">
            <div class="modern-card card-sessions">
                <div class="card-header">
                    <div class="header-content">
                        <div class="icon-wrapper">
                            <i class="fas fa-sign-in-alt header-icon"></i>
                        </div>
                        <div class="header-text">
                            <h4>آخر 5 زيارات</h4>
                            <p class="card-subtitle">أحدث تسجيلات الدخول</p>
                        </div>
                    </div>
                    <div class="header-badge">
                        <span>5</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="sessions-list">
                        <?php
                        $restime = $conn->query("SELECT * FROM session_time order by crtime desc limit 5");
                        $d = 0;
                        while ($rowtime = $restime->fetch_assoc()) { 
                            $d++;
                        ?>
                        <div class="session-item">
                            <div class="session-avatar">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="session-info">
                                <div class="session-user">
                                    <?php 
                                    $usid = $rowtime['user'];
                                    $uname_result = $conn->query("SELECT uname FROM users WHERE id = $usid");
                                    $uname_data = $uname_result ? $uname_result->fetch_assoc() : null;
                                    echo $uname_data ? $uname_data['uname'] : '__';
                                    ?>
                                </div>
                                <div class="session-time"><?= $rowtime['crtime'] ?></div>
                            </div>
                            <div class="session-status active"></div>
                        </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- المبيعات -->
        <div class="col-xl-4 col-lg-6">
            <div class="modern-card card-sales">
                <div class="card-header">
                    <div class="header-content">
                        <div class="icon-wrapper">
                            <i class="fas fa-chart-pie header-icon"></i>
                        </div>
                        <div class="header-text">
                            <h4>المبيعات</h4>
                            <p class="card-subtitle">إحصائيات المبيعات</p>
                        </div>
                    </div>
                    <div class="header-badge trend-up">
                        <i class="fas fa-trending-up"></i>
                    </div>
                </div>
                <div class="card-body">
                    <?php 
                    $sales1 = $conn->query("SELECT pro_value FROM ot_head where pro_tybe = 3 OR pro_tybe = 9 AND isdeleted = 0 order by id desc")->fetch_assoc();
                    $sales2 = $conn->query("SELECT sum(pro_value) FROM ot_head where pro_tybe = 3 OR pro_tybe = 9 AND isdeleted = 0 AND pro_date BETWEEN DATE_SUB(NOW(), INTERVAL 7 DAY) AND NOW()")->fetch_assoc();
                    $sales3 = $conn->query("SELECT sum(pro_value) FROM ot_head where pro_tybe = 3 OR pro_tybe = 9 AND isdeleted = 0 AND pro_date BETWEEN DATE_SUB(NOW(), INTERVAL 30 DAY) AND NOW()")->fetch_assoc();
                    $sales4 = $conn->query("SELECT sum(pro_value) FROM ot_head where pro_tybe = 3 OR pro_tybe = 9 AND isdeleted = 0")->fetch_assoc();
                    ?>
                    <div class="sales-stats">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-receipt"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?= $sales1 ? number_format($sales1['pro_value'], 2) : '0.00' ?></div>
                                <div class="stat-label">آخر فاتورة</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-calendar-week"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?= $sales2['sum(pro_value)'] ? number_format($sales2['sum(pro_value)'], 2) : '0.00' ?></div>
                                <div class="stat-label">آخر أسبوع</div>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-calendar-alt"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?= $sales3['sum(pro_value)'] ? number_format($sales3['sum(pro_value)'], 2) : '0.00' ?></div>
                                <div class="stat-label">آخر 30 يوم</div>
                            </div>
                        </div>
                        <div class="stat-card highlight">
                            <div class="stat-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="stat-content">
                                <div class="stat-value"><?= $sales4['sum(pro_value)'] ? number_format($sales4['sum(pro_value)'], 2) : '0.00' ?></div>
                                <div class="stat-label">إجمالي المبيعات</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- الزيارات الأخيرة -->
        <div class="col-xl-4 col-lg-6">
            <div class="modern-card card-reservations">
                <div class="card-header">
                    <div class="header-content">
                        <div class="icon-wrapper">
                            <i class="fas fa-calendar-alt header-icon"></i>
                        </div>
                        <div class="header-text">
                            <h4>الزيارات الأخيرة</h4>
                            <p class="card-subtitle">أحدث 5 زيارات</p>
                        </div>
                    </div>
                    <div class="header-badge">
                        <span>5</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="visits-list">
                        <?php
                        $resres = $conn->query("SELECT * FROM reservations order by id desc limit 5");
                        $d = 0;
                        while ($rowres = $resres->fetch_assoc()) { 
                            $d++;
                        ?>
                        <div class="visit-item">
                            <div class="visit-avatar">
                                <i class="fas fa-user-clock"></i>
                            </div>
                            <div class="visit-info">
                                <div class="visit-client">
                                    <?php 
                                    $clid = $rowres['client'];
                                    $rowcl_result = $conn->query("SELECT name FROM clients WHERE id = $clid");
                                    $rowcl_data = $rowcl_result ? $rowcl_result->fetch_assoc() : null;
                                    echo $rowcl_data ? $rowcl_data['name'] : '__';
                                    ?>
                                </div>
                                <div class="visit-details">
                                    <span class="visit-date"><?= $rowres['date'] ?></span>
                                    <span class="visit-time"><?= $rowres['time'] ?></span>
                                </div>
                            </div>
                        </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if($role['sid_rents'] == 1){ ?>
        <!-- الأقساط المستحقة -->
        <div class="col-xl-8 col-lg-12">
            <div class="modern-card card-installments">
                <div class="card-header">
                    <div class="header-content">
                        <div class="icon-wrapper">
                            <i class="fas fa-money-bill-wave header-icon"></i>
                        </div>
                        <div class="header-text">
                            <h4>الاقساط المستحقة</h4>
                            <p class="card-subtitle">الأقساط التي تجاوزت تاريخ الاستحقاق</p>
                        </div>
                    </div>
                    <div class="header-badge warning">
                        <span>مستحق</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-wrapper">
                        <div class="modern-table">
                            <div class="table-header">
                                <div class="table-row header-row">
                                    <div class="table-cell">#</div>
                                    <div class="table-cell">الوحدة</div>
                                    <div class="table-cell">العميل</div>
                                    <div class="table-cell">تاريخ الاستحقاق</div>
                                    <div class="table-cell">المستحق</div>
                                    <div class="table-cell">المدفوع</div>
                                    <div class="table-cell">الحالة</div>
                                </div>
                            </div>
                            <div class="table-body">
                                <?php 
                                $x = 0;
                                $resins = $conn->query("SELECT * FROM myinstallments WHERE ins_date < NOW() ORDER BY ins_date LIMIT 5");
                                while ($rowins = $resins->fetch_assoc()) {
                                    $x++;
                                ?>
                                <div class="table-row">
                                    <div class="table-cell">
                                        <span class="serial-number"><?= $x ?></span>
                                    </div>
                                    <div class="table-cell">
                                        <span class="unit-name">
                                            <?php 
                                            $unit_result = $conn->query("SELECT aname FROM acc_head where id = {$rowins['rent_id']}");
                                            $unit_data = $unit_result ? $unit_result->fetch_assoc() : null;
                                            echo $unit_data ? $unit_data['aname'] : '__';
                                            ?>
                                        </span>
                                    </div>
                                    <div class="table-cell">
                                        <span class="client-name">
                                            <?php 
                                            $client_result = $conn->query("SELECT aname FROM acc_head where id = {$rowins['cl_id']}");
                                            $client_data = $client_result ? $client_result->fetch_assoc() : null;
                                            echo $client_data ? $client_data['aname'] : '__';
                                            ?>
                                        </span>
                                    </div>
                                    <div class="table-cell">
                                        <div class="date-display overdue"><?= $rowins['ins_date'] ?></div>
                                    </div>
                                    <div class="table-cell">
                                        <div class="amount-display due"><?= number_format($rowins['ins_value'], 2) ?></div>
                                    </div>
                                    <div class="table-cell">
                                        <div class="amount-display paid"><?= number_format($rowins['ins_paid'], 2) ?></div>
                                    </div>
                                    <div class="table-cell">
                                        <span class="status-tag status-<?= $rowins['ins_case'] ?>">
                                            <?= ($rowins['ins_case'] == 2) ? "مستحق" : (($rowins['ins_case'] == 3) ? "مدفوع" : "___"); ?>
                                        </span>
                                    </div>
                                </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <a href="#" class="view-all-btn">
                        <span>عرض جميع الأقساط</span>
                        <i class="fas fa-arrow-left"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php } ?>
    </div>
</div>


