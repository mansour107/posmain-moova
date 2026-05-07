<div class="card border-0 shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center" style="background-color: var(--primary-navy); color: white;">
        <h6 class="mb-0">
            <i class="fas fa-boxes me-2"></i>اختيار الأصناف
        </h6>
        <div class="d-flex align-items-center gap-2">
            <div style="width: 250px; position: relative;">
                <input type="text" class="form-control form-control-sm" id="searchItems" placeholder="بحث عن صنف..." style="background: white; padding-left: 35px;">
                <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #6c757d;"></i>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="categories-grid" id="categoriesContainer">
            <?php
            $rescategories = $conn->query("SELECT * FROM item_group WHERE isdeleted = 0 ORDER BY gname");
            if ($rescategories && $rescategories->num_rows > 0) {
                while ($rowcategory = $rescategories->fetch_assoc()) {
                    $categoryId = $rowcategory['id'];
                    $categoryName = htmlspecialchars($rowcategory['gname']);
                    echo '<div class="category-card" data-category="'.$categoryId.'" onclick="loadCategoryItems('.$categoryId.')">
                            <div class="category-icon">
                                <i class="fas fa-folder"></i>
                            </div>
                            <div class="category-name">'.$categoryName.'</div>
                          </div>';
                }
            } else {
                echo '<div class="col-12 text-center text-muted"><p>لا توجد مجموعات متاحة</p></div>';
            }
            ?>
        </div>

        <div class="items-container" id="itemsContainer">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0">الأصناف المتاحة</h6>
                <button class="btn btn-outline-secondary btn-sm" onclick="hideItems()">
                    <i class="fas fa-arrow-right me-1"></i>
                </button>
            </div>
            <div class="items-grid row g-2" id="itemsGrid">
            </div>
        </div>

        <div class="no-items-message" id="noItemsMessage">
            <i class="fas fa-box-open fa-3x mb-3" style="color: var(--soft-gray);"></i>
            <h5>اختر مجموعة لعرض الأصناف</h5>
            <p class="text-muted">قم بالنقر على إحدى المجموعات أعلاه لعرض الأصناف المتاحة</p>
        </div>
    </div>
</div>
