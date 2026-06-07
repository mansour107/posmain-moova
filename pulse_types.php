<?php include('includes/header.php'); ?>
<?php include('includes/navbar.php'); ?>
<?php include('includes/sidebar.php'); ?>

<?php
$types = [];
$res = $conn->query("SELECT * FROM pulse_types WHERE isdeleted = 0 ORDER BY category, name");
while ($row = $res->fetch_assoc()) {
    $types[] = $row;
}
?>

<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-sm-6">
          <h1 class="m-0"><i class="fas fa-tags text-info ml-2"></i> أنواع التقييم</h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-left m-0 bg-transparent p-0">
            <li class="breadcrumb-item"><a href="dashboard.php">الرئيسية</a></li>
            <li class="breadcrumb-item"><a href="pulse.php">Pulse</a></li>
            <li class="breadcrumb-item active">أنواع التقييم</li>
          </ol>
        </div>
      </div>
    </div>
  </section>

  <section class="content">
    <div class="container-fluid">

      <div class="card shadow-sm" style="border-radius: 12px; overflow: hidden;">
        <div class="card-header d-flex justify-content-between align-items-center" style="background: #f8fafc;">
          <h3 class="card-title font-weight-bold"><i class="fas fa-list ml-2"></i> قائمة الأنواع</h3>
          <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#typeModal" onclick="resetModal()" style="border-radius: 8px;">
            <i class="fas fa-plus ml-1"></i> إضافة نوع جديد
          </button>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-hover mb-0">
              <thead style="background: #f1f5f9;">
                <tr>
                  <th>#</th>
                  <th>الأيقونة</th>
                  <th>الاسم</th>
                  <th>التصنيف</th>
                  <th>النقاط</th>
                  <th>العمليات</th>
                </tr>
              </thead>
              <tbody id="typesBody">
                <?php foreach ($types as $i => $t): ?>
                <tr id="type-row-<?= $t['id'] ?>">
                  <td><?= $i + 1 ?></td>
                  <td><i class="<?= htmlspecialchars($t['icon']) ?>" style="font-size:1.3rem; color:<?= $t['category']==='positive'?'#10b981':'#ef4444' ?>"></i></td>
                  <td><strong><?= htmlspecialchars($t['name']) ?></strong></td>
                  <td>
                    <?php if ($t['category'] === 'positive'): ?>
                      <span style="background:#d1fae5;color:#065f46;border-radius:20px;padding:4px 12px;font-weight:600;font-size:0.85rem;">إيجابي</span>
                    <?php else: ?>
                      <span style="background:#fee2e2;color:#991b1b;border-radius:20px;padding:4px 12px;font-weight:600;font-size:0.85rem;">سلبي</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <strong style="color:<?= $t['points']>=0?'#10b981':'#ef4444' ?>"><?= $t['points'] > 0 ? '+' . $t['points'] : $t['points'] ?></strong>
                  </td>
                  <td>
                    <button class="btn btn-sm btn-outline-primary edit-type" style="border-radius:8px"
                      data-id="<?= $t['id'] ?>" data-name="<?= htmlspecialchars($t['name']) ?>"
                      data-category="<?= $t['category'] ?>" data-icon="<?= htmlspecialchars($t['icon']) ?>"
                      data-points="<?= $t['points'] ?>">
                      <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger delete-type" style="border-radius:8px" data-id="<?= $t['id'] ?>">
                      <i class="fas fa-trash"></i>
                    </button>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </section>
</div>

<!-- ═══════════ Add/Edit Modal ═══════════ -->
<div class="modal fade" id="typeModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius: 14px; overflow: hidden;">
      <div class="modal-header" style="background: linear-gradient(135deg, #f0f9ff, #e0f2fe); border-bottom: none;">
        <h5 class="modal-title font-weight-bold" id="modalTitle"><i class="fas fa-plus-circle text-primary ml-2"></i> إضافة نوع تقييم</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <form id="typeForm">
          <input type="hidden" id="type_id" value="0">
          <div class="form-group">
            <label class="font-weight-bold">اسم النوع</label>
            <input type="text" id="type_name" class="form-control" required placeholder="مثال: الالتزام بالمواعيد" style="border-radius: 10px;">
          </div>
          <div class="form-group">
            <label class="font-weight-bold">التصنيف</label>
            <select id="type_category" class="form-control" style="border-radius: 10px;">
              <option value="positive">🟢 إيجابي</option>
              <option value="negative">🔴 سلبي</option>
            </select>
          </div>
          <div class="row">
            <div class="col-6">
              <div class="form-group">
                <label class="font-weight-bold">الأيقونة</label>
                <select id="type_icon" class="form-control" style="border-radius: 10px;">
                  <option value="fas fa-star">⭐ نجمة (Star)</option>
                  <option value="fas fa-award">🏆 جائزة / تميز (Award)</option>
                  <option value="fas fa-clock">⏰ ساعة / التزام (Clock)</option>
                  <option value="fas fa-users">👥 فريق (Team)</option>
                  <option value="fas fa-lightbulb">💡 فكرة / مبادرة (Idea)</option>
                  <option value="fas fa-handshake">🤝 مصافحة / عملاء (Handshake)</option>
                  <option value="fas fa-broom">🧹 مكنسة / ترتيب (Broom)</option>
                  <option value="fas fa-exclamation-triangle">⚠️ تحذير / إهمال (Warning)</option>
                  <option value="fas fa-user-slash">🚫 عدم تعاون (User Slash)</option>
                  <option value="fas fa-frown">🙁 عبوس / سوء معاملة (Frown)</option>
                  <option value="fas fa-thumbs-up">👍 إيجابي (Thumbs Up)</option>
                  <option value="fas fa-thumbs-down">👎 سلبي (Thumbs Down)</option>
                  <option value="fas fa-heart">❤️ إخلاص (Heart)</option>
                  <option value="fas fa-bolt">⚡ سرعة / نشاط (Bolt)</option>
                  <option value="fas fa-smile">😊 ابتسامة (Smile)</option>
                </select>
              </div>
            </div>
            <div class="col-6">
              <div class="form-group">
                <label class="font-weight-bold">النقاط</label>
                <input type="number" id="type_points" class="form-control" value="1" style="border-radius: 10px;">
                <small class="form-text text-muted">موجب للإيجابي، سالب للسلبي</small>
              </div>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer" style="border-top: none;">
        <button type="button" class="btn btn-light" data-dismiss="modal" style="border-radius: 10px;">إلغاء</button>
        <button type="button" class="btn btn-primary" id="saveTypeBtn" style="border-radius: 10px; font-weight: 700;">
          <i class="fas fa-check ml-1"></i> حفظ
        </button>
      </div>
    </div>
  </div>
</div>

<?php include('includes/footer.php'); ?>

<script>
function resetModal() {
    $('#type_id').val(0);
    $('#type_name').val('');
    $('#type_category').val('positive');
    $('#type_icon').val('fas fa-star');
    $('#type_points').val(1);
    $('#modalTitle').html('<i class="fas fa-plus-circle text-primary ml-2"></i> إضافة نوع تقييم');
}

$(function() {
    // Edit
    $(document).on('click', '.edit-type', function() {
        $('#type_id').val($(this).data('id'));
        $('#type_name').val($(this).data('name'));
        $('#type_category').val($(this).data('category'));
        $('#type_icon').val($(this).data('icon'));
        $('#type_points').val($(this).data('points'));
        $('#modalTitle').html('<i class="fas fa-edit text-primary ml-2"></i> تعديل نوع تقييم');
        $('#typeModal').modal('show');
    });

    // Save
    $('#saveTypeBtn').on('click', function() {
        var name = $('#type_name').val().trim();
        if (!name) { toastr.warning('الاسم مطلوب'); return; }

        $.post('ajax/pulse_ajax.php', {
            action: 'save_type',
            id: $('#type_id').val(),
            name: name,
            category: $('#type_category').val(),
            icon: $('#type_icon').val(),
            points: $('#type_points').val()
        }, function(res) {
            if (res.success) {
                toastr.success('تم الحفظ');
                $('#typeModal').modal('hide');
                location.reload();
            } else {
                toastr.error(res.error || 'حدث خطأ');
            }
        }, 'json');
    });

    // Delete
    $(document).on('click', '.delete-type', function() {
        var id = $(this).attr('data-id') || $(this).data('id') || $(this).closest('.delete-type').attr('data-id');
        console.log("Delete type triggered for ID:", id);
        if (!id) {
            toastr.error("لم يتم العثور على معرف للنوع المراد حذفه");
            return;
        }

        var performDelete = function() {
            $.post('ajax/pulse_ajax.php', { action: 'delete_type', id: id }, function(res) {
                if (res.success) {
                    $('#type-row-'+id).fadeOut(300, function(){ $(this).remove(); });
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
                title: 'حذف النوع؟',
                text: 'سيتم إخفاؤه من القائمة',
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
        } else if (confirm('هل أنت متأكد من حذف هذا النوع؟')) {
            performDelete();
        }
    });
});
</script>
