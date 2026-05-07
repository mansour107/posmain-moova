<!-- جدول الأصناف -->
<div class="row mb-3" id="upRight1">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-info text-white">
                <h6 class="mb-0">
                    <i class="fas fa-shopping-cart"></i> الأصناف المُضافة
                </h6>
            </div>
            <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-dark sticky-top">
                        <tr class="text-center">
                            <th style="width: 50px;">م</th>
                            <th>الصنف</th>
                            <th style="width: 100px;">الكمية</th>
                            <th style="width: 100px;">السعر</th>
                            <th style="width: 100px;">القيمة</th>
                            <th style="width: 60px;">حذف</th>
                        </tr>
                    </thead> 
                    <tbody id="itemData">
                        <?php
                        if (isset($_GET['edit'])){
                            $id = $_GET['edit'];
                            $sqldet = "SELECT * FROM fat_details where pro_id = $id AND isdeleted  = 0";
                            $resdet = $conn->query($sqldet);
                            $x = 0;
                            while ($rowdet = $resdet->fetch_assoc()) {
                                $x++;
                        ?>
                            <tr data-itemid="${itemData.barcode}" class="align-middle">
                                <td class="text-center fw-bold"><?= $x?></td>
                                <td class="barcode" hidden>${itemData.barcode}</td>
                                <td class="iname">
                                    <input type="hidden" value='${itemData.id}' name="itmname[]">
                                    <span class="text-primary fw-bold">${itemData.iname}</span>
                                </td>
                                <td class="qty">
                                    <input type="number" 
                                           class="form-control form-control-sm text-center cashInput quantityInput select-all nozero" 
                                           value="${qty}" 
                                           name="itmqty[]"
                                           min="1"
                                           step="0.1">
                                    <input type="hidden" name="u_val[]" value="1">
                                </td>
                                <td class="price">
                                    <div class="input-group input-group-sm">
                                        <input type="number" 
                                               class="form-control text-center cashInput priceInput select-all nozero" 
                                               value="${price.toFixed(2)}" 
                                               name="itmprice[]"
                                               step="0.01">
                                        <span class="input-group-text">ج</span>
                                    </div>
                                </td>
                                <td>
                                    <input type="hidden" name="itmdisc[]">
                                    <input type="text" 
                                           class="form-control form-control-sm text-center subtotal cashInput bg-light" 
                                           readonly 
                                           value="${subtotal.toFixed(2)}" 
                                           name="itmval[]">
                                </td>
                                <td class="delRow text-center">
                                    <button type="button" class="btn btn-danger btn-sm" title="حذف">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php    }}; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-light text-muted small">
                <i class="fas fa-info-circle"></i> انقر على الأصناف أو امسح الباركود للإضافة
            </div>
        </div>
    </div>
</div>
