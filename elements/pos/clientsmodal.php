
<div class="modal fade" id="addclmodal" aria-hidden="true" style="display: none;">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">اضافه عميل جديد في قاعدة البيانات</h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">×</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="addClientForm" >
                    <div class="form-group">
                        <label for="clname">اسم العميل</label>
                        <input type="text" class="form-control" id="clname" name="clname" placeholder="name" required>
                    </div>
                    <div class="form-group">
                        <label for="phone">تليفون</label>
                        <input type="text" class="form-control" id="phone" name="phone" placeholder="phone" required>
                    </div>
                    <div class="form-group">
                        <label for="phone2">تليفون2</label>
                        <input type="text" class="form-control" id="phone2" name="phone2" placeholder="phone2">
                    </div>
                    <div class="form-group">
                        <label for="address">عنوان</label>
                        <input type="text" class="form-control" id="address" name="address" placeholder="address">
                    </div>
                    <div class="form-group">
                        <label for="address2">عنوان 2</label>
                        <input type="text" class="form-control" id="address2" name="address2" placeholder="address2">
                    </div>
                    <div class="form-group">
                        <label for="address3">عنوان 3</label>
                        <input type="text" class="form-control" id="address3" name="address3" placeholder="address3">
                    </div>
                    <div class="modal-footer justify-content-between">
                        <button type="submit" class="btn btn-success btn-block" onclick=" dis();">حفظ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
