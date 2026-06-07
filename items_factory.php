<?php
require_once __DIR__ . '/includes/production_guard.php';
production_guard_deny_route('items_factory.php');
require_once __DIR__ . '/includes/csrf.php';
include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
    <!-- Header -->
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2 align-items-center">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark">
                        <i class="fas fa-magic text-info ml-2 animate__animated animate__bounceIn"></i>
                        مصنع الأصناف (Items Factory)
                    </h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-left m-0 bg-transparent p-0">
                        <li class="breadcrumb-item"><a href="dashboard.php">الرئيسية</a></li>
                        <li class="breadcrumb-item"><a href="myitems.php">الأصناف</a></li>
                        <li class="breadcrumb-item active">مصنع الأصناف</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <!-- Main Content -->
    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <!-- Settings Panel -->
                <div class="col-md-5">
                    <div class="card card-outline card-info shadow-lg border-0 rounded-lg overflow-hidden">
                        <div class="card-header bg-gradient-info text-white p-3">
                            <h3 class="card-title font-weight-bold mb-0">
                                <i class="fas fa-cogs ml-2"></i> إعدادات التوليد التلقائي
                            </h3>
                        </div>
                        <div class="card-body bg-light">
                            <form id="factoryForm">
                                <div class="form-group mb-4">
                                    <label for="itemsCount" class="font-weight-bold text-muted small mb-2 d-block">عدد الأصناف المطلوب إنشاؤها</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text bg-white"><i class="fas fa-cubes text-info"></i></span>
                                        </div>
                                        <input type="number" id="itemsCount" class="form-control text-center font-weight-bold" value="9999" min="1" max="9999" required>
                                    </div>
                                    <small class="form-text text-muted">يمكنك توليد حتى 9,999 صنف تجريبي كحد أقصى.</small>
                                </div>

                                <div class="form-group mb-3">
                                    <div class="custom-control custom-switch custom-switch-off-danger custom-switch-on-success">
                                        <input type="checkbox" class="custom-control-input" id="clearDb" checked>
                                        <label class="custom-control-label font-weight-bold text-danger cursor-pointer" for="clearDb">
                                            تصفير وحذف الأصناف الحالية بالكامل
                                        </label>
                                    </div>
                                    <small class="form-text text-muted pr-4">سيتم إفراغ جدول الأصناف وبيانات الوحدات الحالية للبدء ببيانات نظيفة.</small>
                                </div>

                                <div class="form-group mb-4">
                                    <div class="custom-control custom-switch custom-switch-off-warning custom-switch-on-success">
                                        <input type="checkbox" class="custom-control-input" id="clearGroups" checked>
                                        <label class="custom-control-label font-weight-bold text-warning cursor-pointer" for="clearGroups">
                                            تصفير وإعادة تهيئة فئات الأصناف (المجموعات)
                                        </label>
                                    </div>
                                    <small class="form-text text-muted pr-4">سيتم مسح المجموعات الحالية وإنشاء 10 مجموعات أساسية متنوعة باللغة العربية.</small>
                                </div>

                                <button type="submit" id="startBtn" class="btn btn-info btn-block btn-lg shadow-sm font-weight-bold py-3 mt-4 animate__animated animate__pulse animate__infinite animate__slower">
                                    <i class="fas fa-play ml-2"></i> بدء التوليد السحري 🚀
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Live Progress Panel -->
                <div class="col-md-7">
                    <div id="progressPanel" class="card card-outline card-success shadow-lg border-0 rounded-lg d-none">
                        <div class="card-header bg-gradient-success text-white p-3">
                            <h3 class="card-title font-weight-bold mb-0">
                                <i class="fas fa-spinner fa-spin ml-2"></i> حالة عملية التوليد
                            </h3>
                        </div>
                        <div class="card-body">
                            <!-- Progress Bar -->
                            <div class="mb-4">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="font-weight-bold text-muted small" id="progressStatus">جاري التحضير...</span>
                                    <span class="font-weight-bold text-success" id="progressPercent">0%</span>
                                </div>
                                <div class="progress progress-xxs rounded-pill" style="height: 12px;">
                                    <div id="progressBar" class="progress-bar bg-success progress-bar-striped progress-bar-animated rounded-pill" role="progressbar" style="width: 0%"></div>
                                </div>
                            </div>

                            <!-- Live Stats -->
                            <div class="row text-center mb-4">
                                <div class="col-md-4 col-6 mb-3">
                                    <div class="p-3 bg-light rounded-lg border">
                                        <h5 class="text-muted small mb-1">الأصناف المولدة</h5>
                                        <h3 class="font-weight-bold text-dark mb-0" id="statGenerated">0</h3>
                                    </div>
                                </div>
                                <div class="col-md-4 col-6 mb-3">
                                    <div class="p-3 bg-light rounded-lg border">
                                        <h5 class="text-muted small mb-1">الوقت المستغرق</h5>
                                        <h3 class="font-weight-bold text-dark mb-0" id="statTime">0.0 ثانية</h3>
                                    </div>
                                </div>
                                <div class="col-md-4 col-12 mb-3">
                                    <div class="p-3 bg-light rounded-lg border">
                                        <h5 class="text-muted small mb-1">سرعة التوليد</h5>
                                        <h3 class="font-weight-bold text-dark mb-0" id="statSpeed">0 صنف/ثانية</h3>
                                    </div>
                                </div>
                            </div>

                            <!-- Log Terminal -->
                            <label class="font-weight-bold text-muted small mb-2 d-block">سجل العمليات المباشر</label>
                            <div id="terminalLog" class="p-3 bg-dark text-success rounded-lg font-monospace overflow-auto" style="height: 250px; font-family: monospace; font-size: 0.85rem; line-height: 1.6;">
                                <div class="text-white-50">// في انتظار بدء العملية...</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<style>
.cursor-pointer {
    cursor: pointer;
}
#terminalLog::-webkit-scrollbar {
    width: 6px;
}
#terminalLog::-webkit-scrollbar-track {
    background: #1e293b;
}
#terminalLog::-webkit-scrollbar-thumb {
    background: #475569;
    border-radius: 3px;
}
</style>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const factoryForm = document.getElementById("factoryForm");
    const progressPanel = document.getElementById("progressPanel");
    const startBtn = document.getElementById("startBtn");
    const factoryCsrfToken = <?= json_encode(csrf_token('items_factory'), JSON_UNESCAPED_UNICODE) ?>;
    
    const progressBar = document.getElementById("progressBar");
    const progressPercent = document.getElementById("progressPercent");
    const progressStatus = document.getElementById("progressStatus");
    
    const statGenerated = document.getElementById("statGenerated");
    const statTime = document.getElementById("statTime");
    const statSpeed = document.getElementById("statSpeed");
    
    const terminalLog = document.getElementById("terminalLog");

    let count = 0;
    let clearDb = false;
    let clearGroups = false;
    
    const chunkSize = 200;
    let currentChunk = 0;
    let totalChunks = 0;
    
    let startTime = 0;
    let timerInterval = null;

    factoryForm.addEventListener("submit", function(e) {
        e.preventDefault();

        count = parseInt(document.getElementById("itemsCount").value);
        clearDb = document.getElementById("clearDb").checked;
        clearGroups = document.getElementById("clearGroups").checked;
        
        if (count < 1) return;

        // Confirm DB clear action
        if (clearDb) {
            if (!confirm("تنبيه هام جداً:\nخيار 'تصفير وحذف الأصناف الحالية بالكامل' سوف يمسح كافة الأصناف المخزنة حالياً في النظام بشكل نهائي.\nهل ترغب بالاستمرار؟")) {
                return;
            }
        }

        // Initialize state
        currentChunk = 0;
        totalChunks = Math.ceil(count / chunkSize);
        
        // UI transitions
        startBtn.disabled = true;
        startBtn.innerHTML = '<i class="fas fa-spinner fa-spin ml-2"></i> جاري توليد البيانات...';
        startBtn.classList.remove("animate__pulse", "animate__infinite");
        
        progressPanel.classList.remove("d-none");
        progressPanel.classList.add("animate__animated", "animate__fadeInUp");
        
        terminalLog.innerHTML = '<div class="text-info">[بدء] تهيئة عملية توليد ' + count + ' صنف تجريبي...</div>';
        
        startTime = performance.now();
        statGenerated.textContent = "0";
        statTime.textContent = "0.0 ثانية";
        statSpeed.textContent = "0 صنف/ثانية";
        
        // Start live timer
        if (timerInterval) clearInterval(timerInterval);
        timerInterval = setInterval(updateStatsTime, 100);

        // Run first chunk
        runChunk();
    });

    function updateStatsTime() {
        const elapsed = (performance.now() - startTime) / 1000;
        statTime.textContent = elapsed.toFixed(1) + " ثانية";
        
        const generated = parseInt(statGenerated.textContent) || 0;
        if (elapsed > 0) {
            const speed = Math.round(generated / elapsed);
            statSpeed.textContent = speed + " صنف/ثانية";
        }
    }

    function logToTerminal(message, type = 'success') {
        const colorClass = type === 'error' ? 'text-danger' : (type === 'info' ? 'text-info' : 'text-success');
        const timeString = new Date().toLocaleTimeString();
        terminalLog.innerHTML += `<div class="${colorClass}">[${timeString}] ${message}</div>`;
        terminalLog.scrollTop = terminalLog.scrollHeight;
    }

    function runChunk() {
        const offset = currentChunk * chunkSize;
        progressStatus.textContent = `جاري إرسال الدفعة ${currentChunk + 1} من ${totalChunks}...`;

        $.ajax({
            url: "ajax/generate_items.php",
            type: "POST",
            data: {
                action: 'generate',
                csrf_token: factoryCsrfToken,
                count: count,
                clear_db: clearDb ? 1 : 0,
                clear_groups: clearGroups ? 1 : 0,
                chunk: currentChunk,
                chunk_size: chunkSize
            },
            dataType: "json",
            success: function(response) {
                if (response.success) {
                    const generated = response.generated;
                    const items = response.items || [];
                    
                    // Update stats
                    const currentTotal = offset + generated;
                    statGenerated.textContent = currentTotal;
                    
                    // Log generated items in terminal
                    if (currentChunk === 0 && clearDb) {
                        logToTerminal("تم إفراغ قاعدة البيانات بنجاح وبدء جدول أصناف نظيف.", "info");
                    }
                    if (currentChunk === 0 && clearGroups) {
                        logToTerminal("تم تصفير المجموعات وتوليد فئات الأصناف العشر الأساسية.", "info");
                    }
                    
                    logToTerminal(`تم بنجاح توليد الدفعة ${currentChunk + 1} (+${generated} صنف).`);
                    
                    // Write preview of items
                    items.slice(0, 3).forEach(item => {
                        logToTerminal(` -> [${item.code}] ${item.name} (${item.price} ج) - [${item.group}]`, 'info');
                    });
                    if (items.length > 3) {
                        logToTerminal(` -> ... وأكثر من ذلك في هذه الدفعة.`, 'info');
                    }

                    // Calculate overall percent
                    const percent = Math.min(100, Math.round((currentTotal / count) * 100));
                    progressBar.style.width = percent + "%";
                    progressPercent.textContent = percent + "%";

                    if (!response.is_finished && currentChunk + 1 < totalChunks) {
                        currentChunk++;
                        runChunk();
                    } else {
                        // Complete process
                        clearInterval(timerInterval);
                        updateStatsTime();
                        
                        logToTerminal("🎉 تم اكتمال توليد " + currentTotal + " صنف تجريبي بنجاح!");
                        progressStatus.textContent = "اكتملت العملية بنجاح! 🎉";
                        
                        // SweetAlert Success
                        if (typeof Swal !== 'undefined') {
                            Swal.fire({
                                icon: 'success',
                                title: 'عملية توليد ناجحة!',
                                text: `تم توليد ${currentTotal} صنف تجريبي بنجاح في قاعدة البيانات.`,
                                confirmButtonColor: '#17a2b8',
                                confirmButtonText: 'فتح صفحة الأصناف'
                            }).then((result) => {
                                window.location.href = "myitems.php";
                            });
                        } else {
                            alert(`تم توليد ${currentTotal} صنف تجريبي بنجاح!`);
                            window.location.href = "myitems.php";
                        }

                        // Update button state
                        startBtn.innerHTML = '<i class="fas fa-check-circle ml-2"></i> تم التوليد بنجاح! 🎉';
                        startBtn.classList.remove("btn-info");
                        startBtn.classList.add("btn-success");
                    }
                } else {
                    handleError(response.message);
                }
            },
            error: function(xhr, status, error) {
                handleError("فشل الاتصال بالخادم: " + error);
            }
        });
    }

    function handleError(errorMessage) {
        clearInterval(timerInterval);
        logToTerminal("❌ خطأ: " + errorMessage, "error");
        progressStatus.textContent = "فشلت العملية!";
        progressBar.classList.remove("bg-success");
        progressBar.classList.add("bg-danger");
        
        startBtn.disabled = false;
        startBtn.innerHTML = '<i class="fas fa-play ml-2"></i> إعادة المحاولة 🚀';
        
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'error',
                title: 'حدث خطأ أثناء التوليد',
                text: errorMessage,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'حسناً'
            });
        } else {
            alert("حدث خطأ أثناء التوليد: " + errorMessage);
        }
    }
});
</script>

<?php include('includes/footer.php') ?>
