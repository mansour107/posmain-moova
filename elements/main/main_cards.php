<div class="dashboard-cards">
    <div class="row">
        <?php if($role['sid_rents'] == 1){ ?>
        <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
            <div class="stat-card" style="background: #313647 !important;">
                <div class="card-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="card-content">
                    <h2 class="stat-number">
                        <?php 
                            $cnt0 = $conn->query("SELECT COUNT(*) FROM myinstallments where ins_case = 2")->fetch_assoc();
                            echo $cnt0['COUNT(*)']; 
                        ?>
                    </h2>
                    <p class="stat-label">الاقساط المستحقة</p>
                </div>
                <div class="card-footer-det">
                    <a href="#" class="card-link">
                        <span>عرض التفاصيل</span>
                        <i class="fas fa-chevron-left"></i>
                    </a>
                </div>
                <div class="wave-effect"></div>
            </div>
        </div>
        <?php } ?>

        <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
            <div class="stat-card" style="background: #942C21 !important;">
                <div class="card-icon">
                    <i class="fas fa-users"></i>
                </div>
                <div class="card-content">
                    <h2 class="stat-number">
                        <?php 
                            $cnt1 = $conn->query("SELECT COUNT(*) FROM acc_head where is_basic = 0 AND isdeleted = 0 AND code like '122%'")->fetch_assoc();
                            echo $cnt1['COUNT(*)']; 
                        ?>
                    </h2>
                    <p class="stat-label">العملاء</p>
                </div>
                <div class="card-footer-det">
                    <a href="acc_report.php?acc=clients" class="card-link">
                        <span>عرض التفاصيل</span>
                        <i class="fas fa-chevron-left"></i>
                    </a>
                </div>
                <div class="wave-effect"></div>
            </div>
        </div>

        <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
            <div class="stat-card" style="background: #E3651D !important;">
                <div class="card-icon">
                    <i class="fas fa-sign-in-alt"></i>
                </div>
                <div class="card-content">
                    <h2 class="stat-number">
                        <?php 
                            $cnt1 = $conn->query("SELECT COUNT(*) FROM session_time")->fetch_assoc();
                            echo $cnt1['COUNT(*)']; 
                        ?>
                    </h2>
                    <p class="stat-label">مرات الدخول</p>
                </div>
                <div class="card-footer-det">
                    <a href="#" class="card-link">
                        <span>عرض التفاصيل</span>
                        <i class="fas fa-chevron-left"></i>
                    </a>
                </div>
                <div class="wave-effect"></div>
            </div>
        </div>

        <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
            <div class="stat-card" style="background: #313647 !important;">
                <div class="card-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="card-content">
                    <h2 class="stat-number">
                        <?php 
                            $cnt7 = $conn->query("SELECT COUNT(*) FROM ot_head where pro_tybe = 3 OR pro_tybe = 9")->fetch_assoc(); 
                            $cnt8 = $conn->query("SELECT sum(pro_value) FROM ot_head where pro_tybe = 3 OR pro_tybe = 9")->fetch_assoc();
                            $cnt09 = number_format($cnt8['sum(pro_value)'] / 1000, 2, '.', '');
                            echo $cnt7['COUNT(*)'] ." / ". $cnt09."K"; 
                        ?>
                    </h2>
                    <p class="stat-label">المبيعات</p>
                </div>
                <div class="card-footer-det">
                    <a href="operations_summary.php?q=buy" class="card-link">
                        <span>عرض التفاصيل</span>
                        <i class="fas fa-chevron-left"></i>
                    </a>
                </div>
                <div class="wave-effect"></div>
            </div>
        </div>

        <?php if($role['sid_sales'] == 1){ ?>
        <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
            <div class="stat-card" style="background: #942C21 !important;">
                <div class="card-icon">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div class="card-content">
                    <h2 class="stat-number">
                        <?php 
                            $cnt1 = $conn->query("SELECT COUNT(*) FROM tasks")->fetch_assoc();
                            echo $cnt1['COUNT(*)']; 
                        ?>
                    </h2>
                    <p class="stat-label">إجمالي الطلبات</p>
                </div>
                <div class="card-footer-det">
                    <a href="#" class="card-link">
                        <span>عرض التفاصيل</span>
                        <i class="fas fa-chevron-left"></i>
                    </a>
                </div>
                <div class="wave-effect"></div>
            </div>
        </div>
        <?php } ?>

        <?php if($role['sid_hr'] == 1){ ?>
        <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
            <div class="stat-card" style="background: #E3651D !important;">
                <div class="card-icon">
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="card-content">
                    <h2 class="stat-number">
                        <?php 
                            $cnt1 = $conn->query("SELECT COUNT(*) FROM tasks where isdeleted is null")->fetch_assoc();
                            echo $cnt1['COUNT(*)']; 
                        ?>
                    </h2>
                    <p class="stat-label">المهمات المعلقة</p>
                </div>
                <div class="card-footer-det">
                    <a href="tasks.php" class="card-link">
                        <span>عرض التفاصيل</span>
                        <i class="fas fa-chevron-left"></i>
                    </a>
                </div>
                <div class="wave-effect"></div>
            </div>
        </div>
        <?php } ?>

        <?php if($role['sid_clinics'] == 1){ ?>
        <div class="col-xl-3 col-lg-4 col-md-6 mb-4">
            <div class="stat-card" style="background: #313647 !important;">
                <div class="card-icon">
                    <i class="fas fa-calendar-check"></i>
                </div>
                <div class="card-content">
                    <h2 class="stat-number">
                        <?php 
                            $cnt1 = $conn->query("SELECT COUNT(*) FROM reservations where duration is not null")->fetch_assoc();
                            echo $cnt1['COUNT(*)']; 
                        ?>
                    </h2>
                    <p class="stat-label">الزيارات المعلقة</p>
                </div>
                <div class="card-footer-det">
                    <a href="reservations.php" class="card-link">
                        <span>عرض التفاصيل</span>
                        <i class="fas fa-chevron-left"></i>
                    </a>
                </div>
                <div class="wave-effect"></div>
            </div>
        </div>
        <?php } ?>
    </div>
</div>

