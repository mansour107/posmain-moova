<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<?php
// Date (Last 30 days)
$sql_date = "SELECT DATE(created_at) as visit_date, COUNT(*) as total_visits FROM customer_visits WHERE isdeleted = 0 GROUP BY DATE(created_at) ORDER BY visit_date ASC LIMIT 30";
$stmt = $conn->prepare($sql_date);
$stmt->execute();
$visits_by_date = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Gender
$sql_gender = "SELECT gender, COUNT(*) as total FROM customer_visits WHERE isdeleted = 0 GROUP BY gender";
$stmt = $conn->prepare($sql_gender);
$stmt->execute();
$visits_by_gender = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Age Group
$sql_age = "SELECT age_group, COUNT(*) as total FROM customer_visits WHERE isdeleted = 0 GROUP BY age_group";
$stmt = $conn->prepare($sql_age);
$stmt->execute();
$visits_by_age = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Mode (solo/group)
$sql_mode = "SELECT mode, COUNT(*) as total FROM customer_visits WHERE isdeleted = 0 GROUP BY mode";
$stmt = $conn->prepare($sql_mode);
$stmt->execute();
$visits_by_mode = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Order Value
$sql_val = "SELECT order_value, COUNT(*) as total FROM customer_visits WHERE isdeleted = 0 GROUP BY order_value";
$stmt = $conn->prepare($sql_val);
$stmt->execute();
$visits_by_val = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Type (new/returning/regular)
$sql_type = "SELECT visit_type, COUNT(*) as total FROM customer_visits WHERE isdeleted = 0 GROUP BY visit_type";
$stmt = $conn->prepare($sql_type);
$stmt->execute();
$visits_by_type = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Labels Mapping
$age_labels = ['under18' => 'أقل من 18', '18_25' => '18-25', '25_40' => '25-40', 'over40' => 'أكبر من 40'];
$gender_labels_map = ['male' => 'ذكر', 'female' => 'أنثى'];
$mode_labels_map = ['solo' => 'فردي', 'group' => 'مجموعة'];
$val_labels_map = ['under60' => 'أقل من 60', 'over60' => 'أكبر من 60'];
$type_labels_map = ['new' => 'جديد', 'returning' => 'عائد', 'regular' => 'منتظم'];

// JSON Encode for JS (Fallback to empty arrays if no data)
$dates_json = json_encode(array_column($visits_by_date ?: [], 'visit_date'));
$visits_counts_json = json_encode(array_column($visits_by_date ?: [], 'total_visits'));

$genders_mapped = array_map(function($g) use ($gender_labels_map) { return $gender_labels_map[$g['gender']] ?? 'غير محدد'; }, $visits_by_gender ?: []);
$genders_json = json_encode($genders_mapped, JSON_UNESCAPED_UNICODE);
$genders_counts_json = json_encode(array_column($visits_by_gender ?: [], 'total'));

$ages_mapped = array_map(function($a) use ($age_labels) { return $age_labels[$a['age_group']] ?? $a['age_group']; }, $visits_by_age ?: []);
$ages_json = json_encode($ages_mapped, JSON_UNESCAPED_UNICODE);
$ages_counts_json = json_encode(array_column($visits_by_age ?: [], 'total'));

$modes_mapped = array_map(function($m) use ($mode_labels_map) { return $mode_labels_map[$m['mode']] ?? $m['mode']; }, $visits_by_mode ?: []);
$modes_json = json_encode($modes_mapped, JSON_UNESCAPED_UNICODE);
$modes_counts_json = json_encode(array_column($visits_by_mode ?: [], 'total'));

$vals_mapped = array_map(function($v) use ($val_labels_map) { return $val_labels_map[$v['order_value']] ?? $v['order_value']; }, $visits_by_val ?: []);
$vals_json = json_encode($vals_mapped, JSON_UNESCAPED_UNICODE);
$vals_counts_json = json_encode(array_column($visits_by_val ?: [], 'total'));

$types_mapped = array_map(function($t) use ($type_labels_map) { return $type_labels_map[$t['visit_type']] ?? $t['visit_type']; }, $visits_by_type ?: []);
$types_json = json_encode($types_mapped, JSON_UNESCAPED_UNICODE);
$types_counts_json = json_encode(array_column($visits_by_type ?: [], 'total'));

?>

<div class="container" style="direction:rtl; margin-top:20px;">
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white">
            <h3 class="card-title mb-0"><i class="fas fa-chart-line me-2"></i> إحصائيات زيارات العملاء</h3>
        </div>
        
        <div class="card-body">
            
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card border border-info shadow-sm">
                        <div class="card-header bg-light">
                            <h5 class="mb-0 text-center"><i class="fas fa-calendar-alt text-primary me-1"></i> الزيارات في آخر 30 يوم</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="visitsDateChart" height="80"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-4 mb-4">
                    <div class="card border border-success shadow-sm h-100">
                        <div class="card-header bg-light">
                            <h5 class="mb-0 text-center"><i class="fas fa-venus-mars text-success me-1"></i> الجنس</h5>
                        </div>
                        <div class="card-body d-flex justify-content-center align-items-center">
                            <div style="width: 100%;">
                                <canvas id="visitsGenderChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4 mb-4">
                    <div class="card border border-warning shadow-sm h-100">
                        <div class="card-header bg-light">
                            <h5 class="mb-0 text-center"><i class="fas fa-users text-warning me-1"></i> الفئات العمرية</h5>
                        </div>
                        <div class="card-body d-flex justify-content-center align-items-center">
                            <canvas id="visitsAgeChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="col-md-4 mb-4">
                    <div class="card border border-danger shadow-sm h-100">
                        <div class="card-header bg-light">
                            <h5 class="mb-0 text-center"><i class="fas fa-user-friends text-danger me-1"></i> نوع الزيارة</h5>
                        </div>
                        <div class="card-body d-flex justify-content-center align-items-center">
                            <div style="width: 100%;">
                                <canvas id="visitsModeChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-4">
                    <div class="card border border-primary shadow-sm h-100">
                        <div class="card-header bg-light">
                            <h5 class="mb-0 text-center"><i class="fas fa-money-bill-wave text-primary me-1"></i> قيمة الطلب</h5>
                        </div>
                        <div class="card-body d-flex justify-content-center align-items-center">
                            <canvas id="visitsValChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="col-md-6 mb-4">
                    <div class="card border border-dark shadow-sm h-100">
                        <div class="card-header bg-light">
                            <h5 class="mb-0 text-center"><i class="fas fa-star text-dark me-1"></i> نوع العميل</h5>
                        </div>
                        <div class="card-body d-flex justify-content-center align-items-center">
                            <canvas id="visitsTypeChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php include('includes/footer.php') ?>

<!-- إعداد الرسوم البيانية لتعمل مع إصدار Chart.js المتضمن مسبقاً في النظام -->
<script>
$(function() {
    
    // 1. Date Line Chart
    var ctxDate = document.getElementById('visitsDateChart').getContext('2d');
    new Chart(ctxDate, {
        type: 'line',
        data: {
            labels: <?= $dates_json ?>,
            datasets: [{
                label: 'عدد الزيارات',
                data: <?= $visits_counts_json ?>,
                borderColor: '#007bff',
                backgroundColor: 'rgba(0, 123, 255, 0.2)',
                borderWidth: 2,
                fill: true,
                pointBackgroundColor: '#007bff',
                pointRadius: 4
            }]
        },
        options: {
            responsive: true,
            legend: { display: false },
            scales: {
                yAxes: [{ ticks: { beginAtZero: true, stepSize: 1 } }]
            }
        }
    });

    // 2. Gender Doughnut Chart
    var ctxGender = document.getElementById('visitsGenderChart').getContext('2d');
    new Chart(ctxGender, {
        type: 'doughnut',
        data: {
            labels: <?= $genders_json ?>,
            datasets: [{
                data: <?= $genders_counts_json ?>,
                backgroundColor: ['#28a745', '#e83e8c', '#6c757d'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            legend: { position: 'bottom' }
        }
    });

    // 3. Age Bar Chart
    var ctxAge = document.getElementById('visitsAgeChart').getContext('2d');
    new Chart(ctxAge, {
        type: 'bar',
        data: {
            labels: <?= $ages_json ?>,
            datasets: [{
                label: 'العدد',
                data: <?= $ages_counts_json ?>,
                backgroundColor: '#ffc107',
                borderColor: '#e0a800',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            legend: { display: false },
            scales: {
                yAxes: [{ ticks: { beginAtZero: true, stepSize: 1 } }]
            }
        }
    });

    // 4. Mode Doughnut Chart
    var ctxMode = document.getElementById('visitsModeChart').getContext('2d');
    new Chart(ctxMode, {
        type: 'pie',
        data: {
            labels: <?= $modes_json ?>,
            datasets: [{
                data: <?= $modes_counts_json ?>,
                backgroundColor: ['#17a2b8', '#dc3545'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            legend: { position: 'bottom' }
        }
    });

    // 5. Value Bar Chart
    var ctxVal = document.getElementById('visitsValChart').getContext('2d');
    new Chart(ctxVal, {
        type: 'bar',
        data: {
            labels: <?= $vals_json ?>,
            datasets: [{
                label: 'العدد',
                data: <?= $vals_counts_json ?>,
                backgroundColor: '#007bff',
                borderColor: '#0056b3',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            legend: { display: false },
            scales: {
                yAxes: [{ ticks: { beginAtZero: true, stepSize: 1 } }]
            }
        }
    });

    // 6. Type Bar Chart
    var ctxType = document.getElementById('visitsTypeChart').getContext('2d');
    new Chart(ctxType, {
        type: 'bar',
        data: {
            labels: <?= $types_json ?>,
            datasets: [{
                label: 'العدد',
                data: <?= $types_counts_json ?>,
                backgroundColor: '#6c757d',
                borderColor: '#343a40',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            legend: { display: false },
            scales: {
                yAxes: [{ ticks: { beginAtZero: true, stepSize: 1 } }]
            }
        }
    });

});
</script>
