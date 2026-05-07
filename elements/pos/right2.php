<!-- قسم الحسابات -->
<div class="row mb-3" id="upRight2">
    <div class="col-12">
        <div class="card border-warning">
            <div class="card-header bg-warning text-dark">
                <h6 class="mb-0">
                    <i class="fas fa-calculator"></i> الحسابات والإجماليات
                </h6>
            </div>
            <div class="card-body">
                <!-- ملاحظات -->
                <div class="mb-3">
                    <label class="form-label fw-bold">
                        <i class="fas fa-sticky-note"></i> ملاحظات
                    </label>
                    <textarea class="form-control" name="info" id="info" rows="2" 
                              placeholder="أضف أي ملاحظات هنا..."></textarea>
                </div>

                <!-- الإجمالي -->
                <div class="row mb-3 align-items-center">
                    <div class="col-4">
                        <label class="mb-0 fw-bold text-primary">
                            <i class="fas fa-coins"></i> الإجمالي
                        </label>
                    </div>
                    <div class="col-8">
                        <div class="input-group">
                            <input class="form-control text-end nozero fw-bold text-primary" 
                                   type="text" 
                                   readonly 
                                   name="headtotal" 
                                   id="total" 
                                   value="0.00"
                                   style="font-size: 1.2rem;">
                            <span class="input-group-text bg-primary text-white">ج.م</span>
                        </div>
                        <input name="headplus" type="hidden">
                    </div>
                </div>

                <!-- الخصم -->
                <div class="row mb-3 align-items-center bg-info bg-opacity-10 p-2 rounded">
                    <div class="col-4">
                        <label class="mb-0 fw-bold text-info">
                            <i class="fas fa-percentage"></i> الخصم 
                            <small class="badge bg-secondary">F6</small>
                        </label>
                    </div>
                    <div class="col-4">
                        <div class="input-group input-group-sm">
                            <input class="form-control text-center nozero" 
                                   type="number" 
                                   id="discperc" 
                                   value="0"
                                   min="0"
                                   max="100"
                                   step="0.1">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="input-group input-group-sm">
                            <input class="form-control text-center nozero" 
                                   type="number" 
                                   name="headdisc" 
                                   id="discount" 
                                   value="0"
                                   step="0.01">
                            <span class="input-group-text">ج.م</span>
                        </div>
                    </div>
                </div>

                <script>
                    $('#discperc').keyup(() => {
                        let total = parseFloat($('#total').val()) || 0;
                        let discount = (total * (parseFloat($('#discperc').val()) || 0) / 100).toFixed(2);
                        $('#discount').val(discount);
                        $('#net_val').val((total - discount).toFixed(2));
                    });
                </script>

                <hr>

                <!-- الصافي -->
                <div class="row mb-3 align-items-center bg-success bg-opacity-10 p-3 rounded">
                    <div class="col-4">
                        <label class="mb-0 fw-bold text-success">
                            <i class="fas fa-check-circle"></i> الصافي
                        </label>
                    </div>
                    <div class="col-8">
                        <div class="input-group input-group-lg">
                            <input class="form-control text-end fw-bold text-success" 
                                   type="text" 
                                   name="headnet" 
                                   id="net_val" 
                                   value="0"
                                   readonly
                                   style="font-size: 1.5rem; border: 2px solid #28a745;">
                            <span class="input-group-text bg-success text-white fw-bold">ج.م</span>
                        </div>
                    </div>
                </div>

                <hr>

                <!-- المدفوع والباقي -->
                <div class="row">
                    <div class="col-6">
                        <label class="form-label fw-bold text-dark">
                            <i class="fas fa-money-bill-wave"></i> المدفوع
                        </label>
                        <div class="input-group">
                            <input class="form-control text-center nozero" 
                                   type="number" 
                                   id="paid" 
                                   value="0.00"
                                   step="0.01">
                            <span class="input-group-text">ج.م</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold text-danger">
                            <i class="fas fa-arrow-left"></i> الباقي
                        </label>
                        <div class="input-group">
                            <input class="form-control text-center nozero bg-danger text-white fw-bold" 
                                   type="text" 
                                   id="change" 
                                   value="0.00"
                                   readonly>
                            <span class="input-group-text bg-danger text-white">ج.م</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- أزرار الحفظ -->
<div class="row" id="downRight2">
    <div class="col-12">
        <div class="card border-success">
            <div class="card-body p-2">
                <div class="row g-2">
                    <!-- زر الحفظ -->
                    <div class="col-6">
                        <button style="width: 100%;" 
                                name="submit" 
                                type="submit" 
                                value="save"
                                class="btn btn-success btn-lg fw-bold"
                                onclick="return dis();">
                            <i class="fas fa-save"></i>
                            <br>
                            <span>حفظ الطلب</span>
                        </button>
                    </div>
                    
                    <!-- زر الحفظ والطباعة -->
                    <div class="col-6">
                        <button name="submit" 
                                type="submit" 
                                value="cash"
                                class="btn btn-primary btn-lg fw-bold"
                                onclick="return dis();">
                            <i class="fas fa-print"></i>
                            <br>
                            <span>حفظ وطباعة</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>