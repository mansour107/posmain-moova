<?php include('includes/header.php'); ?>
<?php include('includes/navbar.php'); ?>
<?php include('includes/sidebar.php'); ?>

<?php
// Fetch employees
$employees = [];
$empRes = $conn->query("SELECT id, name FROM employees WHERE isdeleted != 1 ORDER BY name ASC");
while ($row = $empRes->fetch_assoc()) {
    $employees[] = $row;
}

// Fetch pulse types
$pulseTypes = [];
$typeRes = $conn->query("SELECT * FROM pulse_types WHERE isdeleted = 0 ORDER BY category, name");
while ($row = $typeRes->fetch_assoc()) {
    $pulseTypes[] = $row;
}
?>

<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-sm-6">
          <h1 class="m-0"><i class="fas fa-bolt text-warning ml-2"></i> Pulse — تقييم لحظي</h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-left m-0 bg-transparent p-0">
            <li class="breadcrumb-item"><a href="dashboard.php">الرئيسية</a></li>
            <li class="breadcrumb-item active">Pulse</li>
          </ol>
        </div>
      </div>
    </div>
  </section>

  <section class="content">
    <div class="container-fluid">

      <!-- ═══════════ Quick Log Card ═══════════ -->
      <div class="card shadow-sm mb-4" style="border-top: 4px solid #f59e0b; border-radius: 12px; overflow: hidden;">
        <div class="card-header" style="background: linear-gradient(135deg, #fef3c7, #fff7ed); border-bottom: none;">
          <h3 class="card-title" style="font-weight: 700; color: #92400e;">
            <i class="fas fa-bolt text-warning ml-2"></i> تسجيل تقييم جديد
          </h3>
        </div>
        <div class="card-body">
          <form id="pulseForm">
            <div class="row">
              <!-- Employee Select -->
              <div class="col-md-4 mb-3">
                <label class="font-weight-bold"><i class="fas fa-user ml-1"></i> الموظف</label>
                <select id="pulse_employee" class="form-control select2" style="width:100%" required>
                  <option value="">اختر الموظف...</option>
                  <?php foreach ($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Category Toggle -->
              <div class="col-md-4 mb-3">
                <label class="font-weight-bold"><i class="fas fa-thumbs-up ml-1"></i> التصنيف</label>
                <div class="d-flex gap-2" style="gap: 10px;">
                  <button type="button" class="btn btn-outline-success btn-lg flex-fill pulse-cat-btn active" data-cat="positive"
                    style="border-radius: 12px; font-weight: 700; transition: all 0.3s; border-width: 2px;">
                    <i class="fas fa-smile"></i> إيجابي
                  </button>
                  <button type="button" class="btn btn-outline-danger btn-lg flex-fill pulse-cat-btn" data-cat="negative"
                    style="border-radius: 12px; font-weight: 700; transition: all 0.3s; border-width: 2px;">
                    <i class="fas fa-frown"></i> سلبي
                  </button>
                </div>
                <input type="hidden" id="pulse_category" value="positive">
              </div>

              <!-- Type Select -->
              <div class="col-md-4 mb-3">
                <label class="font-weight-bold"><i class="fas fa-tag ml-1"></i> نوع التقييم</label>
                <select id="pulse_type" class="form-control" required>
                  <option value="">اختر النوع...</option>
                </select>
              </div>
            </div>

            <div class="row">
              <!-- Rating -->
              <div class="col-md-4 mb-3">
                <label class="font-weight-bold"><i class="fas fa-star ml-1"></i> التقييم الرقمي</label>
                <div class="d-flex align-items-center" style="gap: 12px;">
                  <input type="range" id="pulse_rating" class="custom-range flex-fill" min="1" max="10" value="5" style="accent-color: #f59e0b;">
                  <span id="rating_display" class="badge badge-lg" style="font-size: 1.5rem; min-width: 50px; background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; border-radius: 10px; padding: 6px 14px;">5</span>
                </div>
              </div>

              <!-- Notes -->
              <div class="col-md-6 mb-3">
                <label class="font-weight-bold"><i class="fas fa-sticky-note ml-1"></i> ملاحظات</label>
                <textarea id="pulse_notes" class="form-control" rows="1" placeholder="ملاحظات إضافية (اختياري)..." style="border-radius: 10px;"></textarea>
              </div>

              <!-- Submit -->
              <div class="col-md-2 mb-3 d-flex align-items-end">
                <button type="submit" id="pulse_submit" class="btn btn-block btn-lg" style="background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; border-radius: 12px; font-weight: 700; box-shadow: 0 4px 15px rgba(245,158,11,0.3); transition: all 0.3s;">
                  <i class="fas fa-paper-plane"></i> سجّل
                </button>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- ═══════════ Confetti Canvas (hidden) ═══════════ -->
      <canvas id="confettiCanvas" style="position:fixed;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:9999;"></canvas>

      <!-- ═══════════ Recent Logs ═══════════ -->
      <div class="card shadow-sm" style="border-radius: 12px; overflow: hidden;">
        <div class="card-header d-flex justify-content-between align-items-center" style="background: #f8fafc;">
          <h3 class="card-title font-weight-bold"><i class="fas fa-history text-primary ml-2"></i> آخر التقييمات</h3>
          <a href="pulse_stats.php" class="btn btn-sm btn-outline-primary" style="border-radius: 8px;">
            <i class="fas fa-chart-bar ml-1"></i> الإحصائيات
          </a>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0" id="pulseLogs">
              <thead style="background: #f1f5f9;">
                <tr>
                  <th>#</th>
                  <th>الموظف</th>
                  <th>التصنيف</th>
                  <th>النوع</th>
                  <th>التقييم</th>
                  <th>النقاط</th>
                  <th>ملاحظات</th>
                  <th>بواسطة</th>
                  <th>الوقت</th>
                  <th></th>
                </tr>
              </thead>
              <tbody id="logsBody">
                <tr><td colspan="10" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i> جاري التحميل...</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </section>
</div>

<style>
  .pulse-cat-btn.active[data-cat="positive"] {
    background: #10b981 !important; color: #fff !important; border-color: #10b981 !important;
    box-shadow: 0 4px 15px rgba(16,185,129,0.4);
  }
  .pulse-cat-btn.active[data-cat="negative"] {
    background: #ef4444 !important; color: #fff !important; border-color: #ef4444 !important;
    box-shadow: 0 4px 15px rgba(239,68,68,0.4);
  }
  .pulse-badge-positive { background: #d1fae5; color: #065f46; border-radius: 20px; padding: 4px 12px; font-weight: 600; font-size: 0.85rem; }
  .pulse-badge-negative { background: #fee2e2; color: #991b1b; border-radius: 20px; padding: 4px 12px; font-weight: 600; font-size: 0.85rem; }
  .pulse-points-positive { color: #10b981; font-weight: 700; }
  .pulse-points-negative { color: #ef4444; font-weight: 700; }
  #pulse_submit:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(245,158,11,0.4); }
  .pulse-row-enter { animation: pulseRowIn 0.5s ease-out; }
  @keyframes pulseRowIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }
  @keyframes shakeX { 0%,100%{transform:translateX(0)} 25%{transform:translateX(-8px)} 75%{transform:translateX(8px)} }
  .shake-anim { animation: shakeX 0.4s ease-in-out; }
  .rating-stars { display: inline-flex; gap: 2px; }
  .rating-stars .star { color: #e5e7eb; font-size: 0.8rem; }
  .rating-stars .star.filled { color: #f59e0b; }
</style>

<?php include('includes/footer.php'); ?>

<script>
$(function() {
    var allTypes = <?= json_encode($pulseTypes) ?>;

    // ─── Category toggle ───
    function filterTypes(cat) {
        var $sel = $('#pulse_type').empty().append('<option value="">اختر النوع...</option>');
        allTypes.forEach(function(t) {
            if (t.category === cat) {
                $sel.append('<option value="'+t.id+'" data-points="'+t.points+'" data-icon="'+t.icon+'">'+t.name+' ('+t.points+' نقطة)</option>');
            }
        });
    }

    filterTypes('positive');

    $('.pulse-cat-btn').on('click', function() {
        $('.pulse-cat-btn').removeClass('active');
        $(this).addClass('active');
        var cat = $(this).data('cat');
        $('#pulse_category').val(cat);
        filterTypes(cat);
    });

    // ─── Rating slider ───
    $('#pulse_rating').on('input', function() {
        var val = $(this).val();
        $('#rating_display').text(val);
        var hue = (val / 10) * 120; // 0=red, 120=green
        $('#rating_display').css('background', 'linear-gradient(135deg, hsl('+hue+',80%,45%), hsl('+hue+',80%,35%))');
    });

    // ─── Select2 ───
    $('#pulse_employee').select2({ theme: 'bootstrap4', placeholder: 'اختر الموظف...', dir: 'rtl' });

    // ─── Load logs ───
    function loadLogs() {
        $.getJSON('ajax/pulse_ajax.php?action=get_logs&limit=50', function(data) {
            var html = '';
            if (!data.length) {
                html = '<tr><td colspan="10" class="text-center text-muted py-4"><i class="fas fa-inbox"></i> لا توجد تقييمات بعد</td></tr>';
            }
            data.forEach(function(log, i) {
                var catBadge = log.category === 'positive'
                    ? '<span class="pulse-badge-positive"><i class="fas fa-smile"></i> إيجابي</span>'
                    : '<span class="pulse-badge-negative"><i class="fas fa-frown"></i> سلبي</span>';
                var ptsCls = log.category === 'positive' ? 'pulse-points-positive' : 'pulse-points-negative';
                var pts = (log.points > 0 ? '+' : '') + log.points;

                // Rating stars
                var stars = '';
                var r = parseInt(log.rating) || 5;
                for (var s = 1; s <= 10; s++) {
                    stars += '<i class="fas fa-star star '+(s <= r ? 'filled' : '')+'"></i>';
                }

                var time = log.recorded_at ? log.recorded_at.substring(0,16).replace('T',' ') : '';
                html += '<tr class="pulse-row-enter">';
                html += '<td>'+(i+1)+'</td>';
                html += '<td><strong>'+log.emp_name+'</strong></td>';
                html += '<td>'+catBadge+'</td>';
                html += '<td><i class="'+(log.type_icon||'fas fa-star')+' ml-1"></i> '+log.type_name+'</td>';
                html += '<td><div class="rating-stars">'+stars+'</div></td>';
                html += '<td class="'+ptsCls+'">'+pts+'</td>';
                html += '<td>'+(log.notes || '<span class="text-muted">—</span>')+'</td>';
                html += '<td>'+log.recorded_by_name+'</td>';
                html += '<td class="text-muted small">'+time+'</td>';
                html += '<td><button class="btn btn-sm btn-outline-danger delete-log" data-id="'+log.id+'" style="border-radius:8px"><i class="fas fa-trash"></i></button></td>';
                html += '</tr>';
            });
            $('#logsBody').html(html);
        });
    }
    loadLogs();

    // ─── Submit ───
    $('#pulseForm').on('submit', function(e) {
        e.preventDefault();
        var empId = $('#pulse_employee').val();
        var typeId = $('#pulse_type').val();
        var cat = $('#pulse_category').val();
        var rating = $('#pulse_rating').val();
        var notes = $('#pulse_notes').val();

        if (!empId || !typeId) {
            toastr.warning('اختر الموظف ونوع التقييم');
            return;
        }

        $('#pulse_submit').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

        $.post('ajax/pulse_ajax.php', {
            action: 'save_log',
            employee_id: empId,
            type_id: typeId,
            category: cat,
            rating: rating,
            notes: notes
        }, function(res) {
            $('#pulse_submit').prop('disabled', false).html('<i class="fas fa-paper-plane"></i> سجّل');
            if (res.success) {
                if (cat === 'positive') {
                    toastr.success('تم تسجيل التقييم الإيجابي! +' + res.points + ' نقطة');
                    fireConfetti();
                } else {
                    toastr.error('تم تسجيل التقييم السلبي ' + res.points + ' نقطة');
                    $('#pulseForm').closest('.card').addClass('shake-anim');
                    setTimeout(function(){ $('#pulseForm').closest('.card').removeClass('shake-anim'); }, 500);
                }
                // Reset form
                $('#pulse_notes').val('');
                $('#pulse_rating').val(5).trigger('input');
                loadLogs();
            } else {
                toastr.error(res.error || 'حدث خطأ');
            }
        }, 'json');
    });

    // ─── Delete log ───
    $(document).on('click', '.delete-log', function() {
        var id = $(this).attr('data-id') || $(this).data('id') || $(this).closest('.delete-log').attr('data-id');
        console.log("Delete log triggered for ID:", id);
        if (!id) {
            toastr.error("لم يتم العثور على معرف للتقييم المراد حذفه");
            return;
        }
        var $row = $(this).closest('tr');

        var performDelete = function() {
            $.post('ajax/pulse_ajax.php', { action: 'delete_log', id: id }, function(res) {
                if (res.success) {
                    $row.fadeOut(300, function(){ $(this).remove(); });
                    toastr.info('تم الحذف');
                } else {
                    toastr.error(res.error || 'فشل في الحذف');
                }
            }, 'json').fail(function(xhr) {
                toastr.error('خطأ في الخادم: ' + xhr.responseText);
            });
        };

        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: 'حذف التقييم؟',
                text: 'لا يمكن التراجع',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                confirmButtonText: 'نعم، احذف',
                cancelButtonText: 'إلغاء'
            }).then(function(result) {
                if (result.isConfirmed || result.value) {
                    performDelete();
                }
            });
        } else if (confirm('هل أنت متأكد من حذف هذا التقييم؟')) {
            performDelete();
        }
    });

    // ─── Confetti ───
    function fireConfetti() {
        var canvas = document.getElementById('confettiCanvas');
        var ctx = canvas.getContext('2d');
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
        var particles = [];
        var colors = ['#f59e0b','#10b981','#3b82f6','#ef4444','#8b5cf6','#ec4899'];

        for (var i = 0; i < 80; i++) {
            particles.push({
                x: canvas.width / 2 + (Math.random()-0.5)*200,
                y: canvas.height / 2,
                vx: (Math.random()-0.5)*12,
                vy: -Math.random()*15 - 5,
                size: Math.random()*8+4,
                color: colors[Math.floor(Math.random()*colors.length)],
                alpha: 1,
                rotation: Math.random()*360,
                rotSpeed: (Math.random()-0.5)*10
            });
        }

        var frame = 0;
        function animate() {
            ctx.clearRect(0,0,canvas.width,canvas.height);
            particles.forEach(function(p) {
                p.x += p.vx;
                p.y += p.vy;
                p.vy += 0.4;
                p.alpha -= 0.012;
                p.rotation += p.rotSpeed;
                if (p.alpha <= 0) return;
                ctx.save();
                ctx.translate(p.x, p.y);
                ctx.rotate(p.rotation * Math.PI/180);
                ctx.globalAlpha = p.alpha;
                ctx.fillStyle = p.color;
                ctx.fillRect(-p.size/2, -p.size/2, p.size, p.size);
                ctx.restore();
            });
            frame++;
            if (frame < 80) requestAnimationFrame(animate);
            else ctx.clearRect(0,0,canvas.width,canvas.height);
        }
        animate();
    }
});
</script>
