<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background-color: var(--primary-navy); color: white;">
                <h5 class="modal-title">
                    <i class="fas fa-cash-register me-2"></i>الدفع والإجماليات
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="card" style="background-color: var(--neutral-light);">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-4">
                                        <label class="mb-0 fw-bold" style="color: var(--primary-navy);">
                                            <i class="fas fa-coins me-2"></i>الإجمالي
                                        </label>
                                    </div>
                                    <div class="col-8">
                                        <h4 class="mb-0 text-end" style="color: var(--primary-navy);" id="modal_total">0.00 ج.م</h4>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="card border-secondary">
                            <div class="card-header bg-light">
                                <h6 class="mb-0" style="color: var(--text-dark);">
                                    <i class="fas fa-percentage me-2"></i>الخصم
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row g-2">
                                    <div class="col-6">
                                        <label class="form-label fw-bold">الخصم %</label>
                                        <input class="form-control text-center" type="number" id="modal_discperc" value="0" min="0" max="100" step="0.1">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label fw-bold">قيمة الخصم</label>
                                        <input class="form-control text-center" type="number" id="modal_discount" value="0" step="0.01">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="card" style="background-color: rgba(142, 68, 173, 0.1); border-color: var(--primary-violet);">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-4">
                                        <label class="mb-0 fw-bold" style="color: var(--primary-violet);">
                                            <i class="fas fa-check-circle me-2"></i>الصافي
                                        </label>
                                    </div>
                                    <div class="col-8">
                                        <h3 class="mb-0 text-end" style="color: var(--primary-violet);" id="modal_net">0.00 ج.م</h3>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">
                            <i class="fas fa-wallet me-2"></i>الصندوق
                        </label>
                        <select name="fund_id" id="modal_fund_id" class="form-select" required>
                            <?php
                            $resfund = $conn->query("SELECT * FROM `acc_head` WHERE is_fund =1 AND is_basic = 0 AND isdeleted = 0;");
                            $first_fund = true;
                            while ($rowfund = $resfund->fetch_assoc()) { 
                                $selected = '';
                                if($rowstg['def_pos_fund'] == $rowfund['id']){
                                    $selected = "selected";
                                } elseif ($first_fund && empty($rowstg['def_pos_fund'])) {
                                    $selected = "selected";
                                }
                                $first_fund = false;
                            ?>
                            <option <?= $selected ?> value="<?= $rowfund['id'] ?>"><?= $rowfund['aname'] ?></option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-bold">
                            <i class="fas fa-money-bill-wave me-2"></i>المدفوع
                        </label>
                        <input class="form-control form-control-lg text-center fw-bold" type="number" id="modal_paid" value="0.00" step="0.01">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">الباقي</label>
                        <input class="form-control form-control-lg text-center fw-bold bg-danger text-white" type="text" id="modal_change" value="0.00" readonly>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>إلغاء
                </button>
                <button type="button" class="btn btn-navy" onclick="submitPOS('save');">
                    <i class="fas fa-save me-1"></i>حفظ الطلب
                </button>
                <button type="button" class="btn btn-violet" onclick="submitPOS('cash');">
                    <i class="fas fa-print me-1"></i>حفظ وطباعة
                </button>
            </div>
        </div>
    </div>
</div>
