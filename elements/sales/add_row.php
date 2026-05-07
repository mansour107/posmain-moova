<div class="row">
      <div class="table-responsive">
        <table class="table table-condensed table-hover table-striped table-bordered" id="searchTable">
    
          <thead class="bg-teal-500 text-white">
            <tr>
              <th class="col-1 text-center">#</th>
              <th class="col-lg-5 text-center">اسم الصنف</th>
              <th class="text-center">الوحدة</th>
              <th class="text-center">كمية</th>
              <th class="text-center">سعر</th>
              <th class="text-center">خصم</th>
              <th class="text-center">القيمة</th>
              <th class="text-center"></th>
            </tr>
          </thead>

          <tbody id="">
            <tr>
              <td class="col-1">
               
              <div class="tool">
              <a id="addNewElement" class="btn bg-lime-200 btn-sm hadi-white-flash" href="add_item.php" target="_blank">+</a>
            <div class="tooltext">اضافه صنف جديد</div>  
            </div>



              </td>
              <!-- الصنف -->
              <td id="itmTd" class="col-lg-5">
                <input type="text" 
                       id="itemSearchInput" 
                       class="form-control frst" 
                       placeholder="ابحث عن صنف (اكتب 3 أحرف على الأقل)..."
                       autocomplete="off"
                       style="width:100%">
                <input type="hidden" name="myitm[]" id="selectedItemId">
                <div id="searchResults" style="position:absolute; z-index:1000; background:white; border:1px solid #ddd; max-height:300px; overflow-y:auto; display:none; width:100%;"></div>
                <input id="itmprice2" type="number" hidden onclick=sT(this)>
              </td>

                 <!-- الوحدة -->

                 <td>
                <select id="inputUnitSelect" class="form-control form-control-sm" style="width:100px;">
                <option value="">اختر وحدة</option>
                </select>
              </td>

              <!-- الكمية -->
              <td>
                <input type="number" hidden>
                <input id="itmqty" value="1.00" type="number" onclick=sT(this) class="itmqty form-control form-control-sm nozero" style="width:90px;">
              </td>
           
             
              <!-- السعر -->
              <td>
                <input id="itmprice" value="0.00" type="number" onclick=sT(this) class="itmprice form-control form-control-sm nozero" style="width:90px;" step="0.001">
              </td>
              <!-- الخصم -->
              <td>
                <input id="itmdisc" value="0.00" type="number" onclick=sT(this) class="itmdisc form-control form-control-sm nozero" style="width:120px;" step="0.001">
              </td>
              <!-- القيمة -->
              <td>
                <input readonly id="itmval" value="0.00" type="number" class="itmval bg-light form-control form-control-sm nozero" style="width:150px;" step="0.001">
              </td>
              <input id="itmprofit" hidden>
              <td>
                <button type="button" id="addRow" class="btn btn-light">إضافة</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

<!-- Live Search Script - بدون تحميل أي أصناف مسبقاً -->
<script>
(function() {
    const searchInput = document.getElementById('itemSearchInput');
    const searchResults = document.getElementById('searchResults');
    const selectedItemId = document.getElementById('selectedItemId');
    const priceInput = document.getElementById('itmprice');
    
    let searchTimeout;
    let selectedItem = null;
    
    // البحث المباشر
    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        
        clearTimeout(searchTimeout);
        
        if (query.length < 2) {
            searchResults.style.display = 'none';
            return;
        }
        
        searchTimeout = setTimeout(() => {
            searchResults.innerHTML = '<div style="padding:10px; text-align:center;">جاري البحث...</div>';
            searchResults.style.display = 'block';
            
            fetch(`ajax/load_items_lazy.php?search=${encodeURIComponent(query)}&limit=20`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.items.length > 0) {
                        let html = '';
                        data.items.forEach(item => {
                            html += `
                                <div class="search-result-item" 
                                     data-id="${item.id}"
                                     data-name="${item.iname}"
                                     data-price="${item.price1}"
                                     data-barcode="${item.barcode}"
                                     style="padding:10px; cursor:pointer; border-bottom:1px solid #eee;">
                                    <strong>${item.iname}</strong>
                                    ${item.name2 ? ' // ' + item.name2 : ''}
                                    <span style="float:left; color:#10b981;">${item.price1} ج.م</span>
                                </div>
                            `;
                        });
                        searchResults.innerHTML = html;
                        
                        // إضافة event listeners
                        document.querySelectorAll('.search-result-item').forEach(item => {
                            item.addEventListener('click', function() {
                                selectItem({
                                    id: this.dataset.id,
                                    name: this.dataset.name,
                                    price: this.dataset.price,
                                    barcode: this.dataset.barcode
                                });
                            });
                            
                            item.addEventListener('mouseenter', function() {
                                this.style.background = '#f0f9ff';
                            });
                            
                            item.addEventListener('mouseleave', function() {
                                this.style.background = 'white';
                            });
                        });
                    } else {
                        searchResults.innerHTML = '<div style="padding:10px; text-align:center; color:#999;">لا توجد نتائج</div>';
                    }
                })
                .catch(error => {
                    console.error('Search error:', error);
                    searchResults.innerHTML = '<div style="padding:10px; text-align:center; color:#ef4444;">خطأ في البحث</div>';
                });
        }, 300);
    });
    
    // اختيار صنف
    function selectItem(item) {
        selectedItem = item;
        searchInput.value = item.name;
        selectedItemId.value = item.id;
        priceInput.value = item.price;
        searchResults.style.display = 'none';
        
        // تفعيل حقل الكمية
        document.getElementById('itmqty').focus();
    }
    
    // إخفاء النتائج عند الضغط خارجها
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.style.display = 'none';
        }
    });
    
    // مسح عند focus
    searchInput.addEventListener('focus', function() {
        if (this.value && searchResults.children.length > 0) {
            searchResults.style.display = 'block';
        }
    });
})();
</script>
