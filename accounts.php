<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<style>
/* Tree Styles */
ul, #myUL {
  list-style-type: none;
}

#myUL {
  margin: 0;
  padding: 20px;
  background: #f8f9fa;
  border-radius: 8px;
}

/* Account Item */
.tree {
  font-size: 16px;
  margin: 5px 0;
  padding: 8px 12px;
  background: white;
  border-radius: 6px;
  border-right: 3px solid #e0e0e0;
  transition: all 0.3s ease;
}

.tree:hover {
  background: #f0f7ff;
  border-right-color: #2196F3;
  transform: translateX(-3px);
}

/* Caret Styles */
.caret {
  cursor: pointer;
  user-select: none;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 4px 8px;
  border-radius: 4px;
  transition: background 0.2s;
}

.caret:hover {
  background: #e3f2fd;
}

/* Icon for accounts with children */
.caret::before {
  content: "📁";
  font-size: 18px;
  transition: transform 0.3s;
}

.caret-down::before {
  content: "📂";
  transform: rotate(0deg);
}

/* Icon for accounts without children */
.no-children::before {
  content: "📄";
  font-size: 16px;
}

/* Nested Lists */
.nested {
  display: none;
  padding-right: 25px;
  margin-top: 5px;
  border-right: 2px dashed #ddd;
}

.active {
  display: block;
}

/* Level Colors */
.num0 { border-right-color: #f44336; }
.num0:hover { border-right-color: #d32f2f; }

.level-1 { border-right-color: #2196F3; }
.level-1:hover { border-right-color: #1976D2; }

.level-2 { border-right-color: #4CAF50; }
.level-2:hover { border-right-color: #388E3C; }

.level-3 { border-right-color: #FF9800; }
.level-3:hover { border-right-color: #F57C00; }

.level-4 { border-right-color: #9C27B0; }
.level-4:hover { border-right-color: #7B1FA2; }

/* Account Code Badge */
.acc-code {
  background: #e3f2fd;
  color: #1976D2;
  padding: 2px 8px;
  border-radius: 4px;
  font-weight: bold;
  font-size: 14px;
  margin-left: 8px;
}

/* Account Name */
.acc-name {
  color: #333;
  font-weight: 500;
}
</style>

<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      
      <div class="card">
        <!-- Header -->
        <div class="card-header bg-primary text-right" style="padding: 15px 20px;">
          <div class="d-flex justify-content-between align-items-center">
            <a href="add_account.php" class="btn btn-light">
              <i class="fas fa-plus"></i> حساب جديد
            </a>
            <h3 class="m-0" style="color: white;">
              <i class="fas fa-sitemap"></i> شجرة الحسابات
            </h3>
            <div style="width: 100px;"></div>
          </div>
        </div>

        <!-- Tree View -->
        <div class="card-body" style="padding: 20px;">
          <ul id="myUL">
            <?php
            // Get all accounts with their children count
            $sqlacc = 'SELECT a.*, 
                       (SELECT COUNT(*) FROM acc_head WHERE parent_id = a.id AND isdeleted = 0) as children_count
                       FROM acc_head a 
                       WHERE a.parent_id = 0 AND a.isdeleted = 0 
                       ORDER BY a.code';
            $resacc = $conn->query($sqlacc);
            
            while ($rowacc = $resacc->fetch_assoc()) {
              $hasChildren = $rowacc['children_count'] > 0;
              $caretClass = $hasChildren ? 'caret' : 'caret no-children';
            ?>
            <li class="num0 tree">
              <span class="<?= $caretClass ?>">
                <span class="acc-code"><?= htmlspecialchars($rowacc['code']) ?></span>
                <span class="acc-name"><?= htmlspecialchars($rowacc['aname']) ?></span>
              </span>
              
              <?php if ($hasChildren) { ?>
              <ul class="nested">
                <?php
                $p2id = $rowacc['id'];
                $sqlacc2 = "SELECT a.*, 
                           (SELECT COUNT(*) FROM acc_head WHERE parent_id = a.id AND isdeleted = 0) as children_count
                           FROM acc_head a 
                           WHERE a.parent_id = $p2id AND a.isdeleted = 0 
                           ORDER BY a.code";
                $resacc2 = $conn->query($sqlacc2);
                
                while ($rowacc2 = $resacc2->fetch_assoc()) {
                  $hasChildren2 = $rowacc2['children_count'] > 0;
                  $caretClass2 = $hasChildren2 ? 'caret' : 'caret no-children';
                ?>
                <li class="tree level-1">
                  <span class="<?= $caretClass2 ?>">
                    <span class="acc-code"><?= htmlspecialchars($rowacc2['code']) ?></span>
                    <span class="acc-name"><?= htmlspecialchars($rowacc2['aname']) ?></span>
                  </span>
                  
                  <?php if ($hasChildren2) { ?>
                  <ul class="nested">
                    <?php
                    $p3id = $rowacc2['id'];
                    $sqlacc3 = "SELECT a.*, 
                               (SELECT COUNT(*) FROM acc_head WHERE parent_id = a.id AND isdeleted = 0) as children_count
                               FROM acc_head a 
                               WHERE a.parent_id = $p3id AND a.isdeleted = 0 
                               ORDER BY a.code";
                    $resacc3 = $conn->query($sqlacc3);
                    
                    while ($rowacc3 = $resacc3->fetch_assoc()) {
                      $hasChildren3 = $rowacc3['children_count'] > 0;
                      $caretClass3 = $hasChildren3 ? 'caret' : 'caret no-children';
                    ?>
                    <li class="tree level-2">
                      <span class="<?= $caretClass3 ?>">
                        <span class="acc-code"><?= htmlspecialchars($rowacc3['code']) ?></span>
                        <span class="acc-name"><?= htmlspecialchars($rowacc3['aname']) ?></span>
                      </span>
                      
                      <?php if ($hasChildren3) { ?>
                      <ul class="nested">
                        <?php
                        $p4id = $rowacc3['id'];
                        $sqlacc4 = "SELECT a.*, 
                                   (SELECT COUNT(*) FROM acc_head WHERE parent_id = a.id AND isdeleted = 0) as children_count
                                   FROM acc_head a 
                                   WHERE a.parent_id = $p4id AND a.isdeleted = 0 
                                   ORDER BY a.code";
                        $resacc4 = $conn->query($sqlacc4);
                        
                        while ($rowacc4 = $resacc4->fetch_assoc()) {
                          $hasChildren4 = $rowacc4['children_count'] > 0;
                          $caretClass4 = $hasChildren4 ? 'caret' : 'caret no-children';
                        ?>
                        <li class="tree level-3">
                          <span class="<?= $caretClass4 ?>">
                            <span class="acc-code"><?= htmlspecialchars($rowacc4['code']) ?></span>
                            <span class="acc-name"><?= htmlspecialchars($rowacc4['aname']) ?></span>
                          </span>
                        </li>
                        <?php } ?>
                      </ul>
                      <?php } ?>
                    </li>
                    <?php } ?>
                  </ul>
                  <?php } ?>
                </li>
                <?php } ?>
              </ul>
              <?php } ?>
            </li>
            <?php } ?>
          </ul>
        </div>

        <!-- Table View -->
        <div class="card-body" style="padding: 20px; border-top: 2px solid #f0f0f0;">
          <h4 class="mb-3"><i class="fas fa-table"></i> عرض جدولي</h4>
          <div class="table-responsive">
            <table class="table table-hover table-bordered">
              <thead class="bg-light">
                <tr>
                  <th width="50">#</th>
                  <th>الكود</th>
                  <th>الاسم</th>
                  <th>النوع</th>
                  <th>التصنيف</th>
                  <th>يتبع لـ</th>
                  <th width="180" class="text-center">العمليات</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $sqlacc = 'SELECT * FROM acc_head WHERE isdeleted = 0 ORDER BY code';
                $resacc = $conn->query($sqlacc);
                $x = 0;
                while ($rowacc = $resacc->fetch_assoc()) {
                  $x++;
                ?>
                <tr>
                  <td><?= $x ?></td>
                  <td><span class="badge badge-info"><?= htmlspecialchars($rowacc['code']) ?></span></td>
                  <td><?= htmlspecialchars($rowacc['aname']) ?></td>
                  <td>
                    <?php 
                    if ($rowacc['kind'] == 1) {
                      echo '<span class="badge badge-primary">ميزانية</span>';
                    } elseif ($rowacc['kind'] == 2) {
                      echo '<span class="badge badge-success">أرباح وخسائر</span>';
                    }
                    ?>
                  </td>
                  <td>
                    <?php 
                    if ($rowacc['is_basic'] == 1) {
                      echo '<span class="badge badge-warning">حساب أساسي</span>';
                    } else {
                      echo '<span class="badge badge-secondary">حساب عادي</span>';
                    }
                    ?>
                  </td>
                  <td>
                    <?php
                    $accheadid = $rowacc['parent_id'];
                    if ($accheadid != 0) {
                      $stmt = $conn->prepare("SELECT aname FROM acc_head WHERE id = ?");
                      $stmt->bind_param("i", $accheadid);
                      $stmt->execute();
                      $result = $stmt->get_result();
                      if ($rowacchead = $result->fetch_assoc()) {
                        echo htmlspecialchars($rowacchead['aname']);
                      }
                    } else {
                      echo '<span class="text-muted">--</span>';
                    }
                    ?>
                  </td>
                  <td class="text-center">
                    <a href="edit_account.php?id=<?= $rowacc['id'] ?>" class="btn btn-sm btn-warning">
                      <i class="fas fa-edit"></i>
                    </a>
                    <button type="button" class="btn btn-sm btn-danger" data-toggle="modal" data-target="#delete<?= $rowacc['id']?>">
                      <i class="fas fa-trash"></i>
                    </button>
                  </td>
                </tr>

                <!-- Delete Modal -->
                <div class="modal fade" id="delete<?= $rowacc['id']?>">
                  <div class="modal-dialog">
                    <div class="modal-content">
                      <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title">
                          <i class="fas fa-exclamation-triangle"></i> تحذير
                        </h5>
                        <button type="button" class="close text-white" data-dismiss="modal">
                          <span>&times;</span>
                        </button>
                      </div>
                      <div class="modal-body text-center">
                        <i class="fas fa-trash-alt text-danger" style="font-size: 48px;"></i>
                        <p class="mt-3">هل تريد بالتأكيد حذف الحساب:</p>
                        <h5 class="text-danger"><?= htmlspecialchars($rowacc['aname']) ?></h5>
                      </div>
                      <div class="modal-footer justify-content-between">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">
                          <i class="fas fa-times"></i> إلغاء
                        </button>
                        <a href="do/dodel_account.php?id=<?= $rowacc['id'] ?>" class="btn btn-danger">
                          <i class="fas fa-trash"></i> حذف
                        </a>
                      </div>
                    </div>
                  </div>
                </div>
                <?php } ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </div>
  </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  var togglers = document.getElementsByClassName("caret");
  
  for (var i = 0; i < togglers.length; i++) {
    // Skip items without children
    if (togglers[i].classList.contains('no-children')) {
      continue;
    }
    
    togglers[i].addEventListener("click", function() {
      var nested = this.parentElement.querySelector(".nested");
      if (nested) {
        nested.classList.toggle("active");
        this.classList.toggle("caret-down");
      }
    });
  }
});
</script>

<?php include('includes/footer.php') ?>
