<?php 
// Start output buffering to prevent headers already sent error
ob_start();

// Include necessary files
include('includes/connect.php'); // ملف الاتصال بقاعدة البيانات

// Process form submissions before any HTML output

// إضافة طاولة جديدة
if (isset($_POST['add_table'])) {
    $tname = mysqli_real_escape_string($conn, $_POST['tname']);
    $table_case = mysqli_real_escape_string($conn, $_POST['table_case']);
    $sql = "INSERT INTO tables (tname, table_case, crtime, mdtime, isdeleted, branch, tatnet)
            VALUES ('$tname', '$table_case', NOW(), NOW(), 0, 'main', 0)";
    if(mysqli_query($conn, $sql)) {
        header("Location: crud_tables.php");
        exit();
    }
}

// تعديل طاولة
if (isset($_POST['edit_table'])) {
    $id = (int)$_POST['id'];
    $tname = mysqli_real_escape_string($conn, $_POST['tname']);
    $table_case = mysqli_real_escape_string($conn, $_POST['table_case']);

    $sql = "UPDATE tables SET 
                tname='$tname', 
                table_case='$table_case',
                mdtime=NOW()
            WHERE id=$id";
    if(mysqli_query($conn, $sql)) {
        header("Location: crud_tables.php");
        exit();
    }
}

// حذف طاولة (حذف منطقي)
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    if($id > 0) {
        mysqli_query($conn, "UPDATE tables SET isdeleted=1 WHERE id=$id");
        header("Location: crud_tables.php");
        exit();
    }
}

// Now include the header and other UI components
include('includes/header.php');
include('includes/navbar.php');
include('includes/sidebar.php');
?>


  <style>
    body {
      background: #f8f9fa;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    .content-wrapper {
      margin-left: 0;
      margin-right: 250px;
      padding: 20px;
      transition: all 0.3s;
    }
    .sidebar-collapsed .content-wrapper {
      margin-left: 60px;
    }
    .table-responsive {
      background: #fff;
      border-radius: 8px;
      box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
      padding: 1rem;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }
    .table {
      margin-bottom: 0;
      min-width: 600px;
    }
    .table th {
      background-color: #f8f9fa;
      white-space: nowrap;
    }
    .action-buttons .btn {
      margin: 0 2px;
    }
    @media (max-width: 991.98px) {
      .content-wrapper {
        margin-left: 0 !important;
        margin-right: 0 !important;
      }
    }
    @media (max-width: 768px) {
      .content-wrapper {
        padding: 10px;
      }
      .d-flex.justify-content-between {
        flex-direction: column;
        gap: 10px;
      }
      .d-flex.justify-content-between h3 {
        font-size: 1.3rem;
      }
      .d-flex.justify-content-between .btn {
        width: 100%;
      }
      .table {
        font-size: 0.85rem;
      }
      .table th,
      .table td {
        padding: 0.5rem 0.3rem;
      }
      .btn-sm {
        font-size: 0.75rem;
        padding: 0.25rem 0.5rem;
      }
    }
    @media (max-width: 576px) {
      .table {
        font-size: 0.75rem;
      }
      .table th,
      .table td {
        padding: 0.4rem 0.2rem;
      }
    }
  </style>

<div class="content-wrapper">
  <div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3 mb-md-4">
      <h3 class="mb-0">🍽️ إدارة الطاولات</h3>
      <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="fas fa-plus"></i> <span class="d-none d-sm-inline">إضافة طاولة</span><span class="d-inline d-sm-none">إضافة</span>
      </button>
    </div>

    <div class="table-responsive">
      <table class="table table-hover text-center">
    <thead class="table-light">
      <tr>
        <th>ID</th>
        <th>Name</th>
        <!-- <th>الحالة</th> -->
        <th>Created</th>
        <th>Modified</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php
      $result = mysqli_query($conn, "SELECT * FROM tables WHERE isdeleted = 0 ORDER BY id DESC");
      while ($row = mysqli_fetch_assoc($result)):
    ?>
      <tr>
        <td><?= $row['id'] ?></td>
        <td><?= htmlspecialchars($row['tname']) ?></td>
        <!-- <td>

        </td> -->
        <td><?= $row['crtime'] ?></td>
        <td><?= $row['mdtime'] ?></td>
        <td class="text-nowrap">
          <button class="btn btn-sm btn-outline-secondary" 
                  data-bs-toggle="modal" 
                  data-bs-target="#editModal<?= $row['id'] ?>"><i class="fas fa-edit"></i></button>
          <a href="?delete=<?= $row['id'] ?>" class="btn btn-sm btn-outline-danger"
             onclick="return confirm('Delete this table?')"><i class="fas fa-trash"></i></a>
        </td>
      </tr>

      <!-- Modal تعديل -->
      <div class="modal fade" id="editModal<?= $row['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
          <div class="modal-content">
            <form method="POST">
              <div class="modal-header">
                <h5 class="modal-title">Edit Table</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                <div class="mb-3">
                  <label class="form-label">Table Name</label>
                  <input type="text" name="tname" value="<?= htmlspecialchars($row['tname']) ?>" class="form-control" required>
                </div>
                <div class="mb-3">
                  <label class="form-label">حالة الطاولة</label>
                  <select name="table_case" class="form-select" required>
                    <option value="free" <?= $row['table_case'] == 'free' ? 'selected' : '' ?>>متاحة</option>
                    <option value="occupied" <?= $row['table_case'] == 'occupied' ? 'selected' : '' ?>>محجوزة</option>
                    <option value="maintenance" <?= $row['table_case'] == 'maintenance' ? 'selected' : '' ?>>صيانة</option>
                  </select>
                </div>
              </div>
              <div class="modal-footer">
                <button type="submit" name="edit_table" class="btn btn-primary">Save Changes</button>
              </div>
            </form>
          </div>
        </div>
      </div>

    <?php endwhile; ?>
    </tbody>
      </table>
    </div>
  </div>
</div>
<!-- Modal إضافة -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header">
          <h5 class="modal-title">Add New Table</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">اسم الطاولة</label>
            <input type="text" name="tname" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">حالة الطاولة</label>
            <select name="table_case" class="form-select" required>
              <option value="free">متاحة</option>
              <option value="occupied">محجوزة</option>
              <option value="maintenance">صيانة</option>
            </select>
            <label class="form-label">Tatnet</label>
            <input type="text" name="tatnet" class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" name="add_table" class="btn btn-success">Add</button>
        </div>
      </form>
    </div>
  </div>
</div>



<?php 
// End output buffering and flush the output
ob_end_flush();
include('includes/footer.php'); 
?>