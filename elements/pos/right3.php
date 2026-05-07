<!-- أزرار إضافية -->
<div class="row mt-2" id="additionalButtons">
    <div class="col-12">
        <div class="card border-secondary">
            <div class="card-body p-2">
                <div class="row g-2">
                    <div class="col-4">
                        <button type="button" 
                                class="btn btn-warning btn-sm w-100"
                                onclick="if(confirm('مسح كل الأصناف؟')) {$('#itemData').empty(); updateTotal();}">
                            <i class="fas fa-eraser"></i> مسح الكل
                        </button>
                    </div>
                    <div class="col-4">
                        <button type="button" 
                                class="btn btn-info btn-sm w-100"
                                onclick="location.reload();">
                            <i class="fas fa-redo"></i> إعادة تحميل
                        </button>
                    </div>
                    <div class="col-4">
                        <button type="button" 
                                class="btn btn-secondary btn-sm w-100"
                                onclick="window.history.back();">
                            <i class="fas fa-arrow-right"></i> رجوع
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
