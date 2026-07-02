<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<?php
$activeTab = ($_GET['tab'] ?? 'groups') === 'subgroups' ? 'subgroups' : 'groups';
$groupsError = isset($_GET['error']) && $activeTab === 'groups';
$subgroupsError = isset($_GET['error']) && $activeTab === 'subgroups';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="card">

                <div class="card-header text-right" style="padding: 15px 20px;">
                    <h3 class="m-0" style="color: white;">
                        <i class="fas fa-layer-group"></i>
                        <?= $lang_groups_and_categories ?>
                    </h3>
                </div>

                <div class="card-body" style="padding: 20px;">

                    <ul class="nav nav-tabs mb-4" role="tablist">
                        <li class="nav-item">
                            <a
                                class="nav-link <?= $activeTab === 'groups' ? 'active' : '' ?>"
                                id="tab-groups-link"
                                href="#tab-groups"
                                data-toggle="tab"
                                role="tab"
                                aria-controls="tab-groups"
                                aria-selected="<?= $activeTab === 'groups' ? 'true' : 'false' ?>"
                            ><?= $lang_groups ?></a>
                        </li>
                        <li class="nav-item">
                            <a
                                class="nav-link <?= $activeTab === 'subgroups' ? 'active' : '' ?>"
                                id="tab-subgroups-link"
                                href="#tab-subgroups"
                                data-toggle="tab"
                                role="tab"
                                aria-controls="tab-subgroups"
                                aria-selected="<?= $activeTab === 'subgroups' ? 'true' : 'false' ?>"
                            ><?= $lang_categories ?></a>
                        </li>
                    </ul>

                    <div class="tab-content">

                        <!-- التصنيفات -->
                        <div
                            class="tab-pane fade <?= $activeTab === 'groups' ? 'show active' : '' ?>"
                            id="tab-groups"
                            role="tabpanel"
                            aria-labelledby="tab-groups-link"
                        >
                            <?php if ($groupsError): ?>
                                <div class="alert alert-danger alert-dismissible fade show">
                                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <?php if ($_GET['error'] == 'duplicate'): ?>
                                        هذا التصنيف موجود بالفعل! الرجاء اختيار اسم آخر.
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <form action="do/doadd_group.php" method="post" class="form-inline">
                                        <label class="mr-2">التصنيف الجديد:</label>
                                        <input
                                            type="text"
                                            class="form-control mr-2"
                                            name="gname"
                                            placeholder="ادخل تصنيفاً جديداً"
                                            required
                                            style="flex: 1;"
                                        >
                                    </form>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="bg-light">
                                        <tr>
                                            <th width="80">#</th>
                                            <th>اسم التصنيف</th>
                                            <th width="150" class="text-center">العمليات</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $groupsCount = 0;
                                        $resgrb = $conn->query("SELECT * FROM item_group WHERE isdeleted = 0 ORDER BY id ASC");

                                        if ($resgrb->num_rows == 0) {
                                            echo '<tr><td colspan="3" class="text-center text-muted">لا توجد تصنيفات</td></tr>';
                                        }

                                        while ($rowgrb = $resgrb->fetch_assoc()) {
                                            $groupsCount++;
                                        ?>
                                        <tr>
                                            <form action="do/doedit_group.php?id=<?= $rowgrb['id'] ?>" method="post" class="d-contents">
                                                <td><?= $groupsCount ?></td>
                                                <td>
                                                    <input
                                                        type="text"
                                                        name="gname"
                                                        class="form-control"
                                                        value="<?= htmlspecialchars($rowgrb['gname']) ?>"
                                                        required
                                                    >
                                                </td>
                                                <td class="text-center">
                                                    <button type="submit" class="btn btn-sm btn-warning">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a
                                                        href="do/dodel_group.php?id=<?= $rowgrb['id'] ?>"
                                                        class="btn btn-sm btn-danger"
                                                        onclick="return confirm('هل تريد حذف هذا التصنيف؟')"
                                                    >
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </td>
                                            </form>
                                        </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>

                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i>
                                إجمالي التصنيفات: <strong><?= $groupsCount ?></strong>
                            </small>
                        </div>

                        <!-- المجموعات الفرعية -->
                        <div
                            class="tab-pane fade <?= $activeTab === 'subgroups' ? 'show active' : '' ?>"
                            id="tab-subgroups"
                            role="tabpanel"
                            aria-labelledby="tab-subgroups-link"
                        >
                            <?php if ($subgroupsError): ?>
                                <div class="alert alert-danger alert-dismissible fade show">
                                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                                    <i class="fas fa-exclamation-triangle"></i>
                                    <?php if ($_GET['error'] == 'duplicate'): ?>
                                        هذه المجموعة الفرعية موجودة بالفعل! الرجاء اختيار اسم آخر.
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="row mb-4">
                                <div class="col-md-6">
                                    <form action="do/doadd_group2.php" method="post" class="form-inline">
                                        <label class="mr-2">المجموعة الفرعية الجديدة:</label>
                                        <input
                                            type="text"
                                            class="form-control mr-2"
                                            name="gname"
                                            placeholder="ادخل مجموعة فرعية جديدة"
                                            required
                                            style="flex: 1;"
                                        >
                                    </form>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="bg-light">
                                        <tr>
                                            <th width="80">#</th>
                                            <th>اسم المجموعة الفرعية</th>
                                            <th width="150" class="text-center">العمليات</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $subgroupsCount = 0;
                                        $resgrb2 = $conn->query("SELECT * FROM item_group2 WHERE isdeleted = 0 ORDER BY id ASC");

                                        if ($resgrb2->num_rows == 0) {
                                            echo '<tr><td colspan="3" class="text-center text-muted">لا توجد مجموعات فرعية</td></tr>';
                                        }

                                        while ($rowgrb2 = $resgrb2->fetch_assoc()) {
                                            $subgroupsCount++;
                                        ?>
                                        <tr>
                                            <form action="do/doedit_group2.php?id=<?= $rowgrb2['id'] ?>" method="post" class="d-contents">
                                                <td><?= $subgroupsCount ?></td>
                                                <td>
                                                    <input
                                                        type="text"
                                                        name="gname"
                                                        class="form-control"
                                                        value="<?= htmlspecialchars($rowgrb2['gname']) ?>"
                                                        required
                                                    >
                                                </td>
                                                <td class="text-center">
                                                    <button type="submit" class="btn btn-sm btn-warning">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a
                                                        href="do/dodel_group2.php?id=<?= $rowgrb2['id'] ?>"
                                                        class="btn btn-sm btn-danger"
                                                        onclick="return confirm('هل تريد حذف هذه المجموعة الفرعية؟')"
                                                    >
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </td>
                                            </form>
                                        </tr>
                                        <?php } ?>
                                    </tbody>
                                </table>
                            </div>

                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i>
                                إجمالي المجموعات الفرعية: <strong><?= $subgroupsCount ?></strong>
                            </small>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </section>
</div>

<script>
$(function () {
    $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
        var tab = $(e.target).attr('href') === '#tab-subgroups' ? 'subgroups' : 'groups';
        var url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        url.searchParams.delete('error');
        window.history.replaceState({}, '', url.toString());
    });
});
</script>

<?php include('includes/footer.php') ?>
