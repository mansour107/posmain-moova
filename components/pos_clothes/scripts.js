let selectedItems = [];
let allItems = [];
let searchTimeout;
let barcodeTimeout;

// بحث بالباركود
function searchByBarcode() {
    const barcode = document.getElementById('barcodeSearch').value.trim();
    
    if (barcode === '') {
        return;
    }
    
    clearTimeout(barcodeTimeout);
    barcodeTimeout = setTimeout(function() {
        $.ajax({
            url: 'ajax/search_item.php',
            type: 'POST',
            data: { barcode: barcode },
            dataType: 'json',
            success: function(data) {
                console.log('Barcode response:', data);
                if (data.success && data.item) {
                    const item = data.item;
                    addItemToOrder(item.id, item.name, item.price);
                    document.getElementById('barcodeSearch').value = '';
                    document.getElementById('barcodeSearch').focus();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'غير موجود',
                        text: data.message || 'الصنف غير موجود',
                        timer: 1500,
                        showConfirmButton: false,
                        toast: true,
                        position: 'top-end'
                    });
                    document.getElementById('barcodeSearch').value = '';
                }
            },
            error: function(xhr, status, error) {
                console.error('Barcode Search Error:', error);
                console.error('Response:', xhr.responseText);
                Swal.fire({
                    icon: 'error',
                    title: 'خطأ',
                    text: 'حدث خطأ في البحث',
                    timer: 1500,
                    showConfirmButton: false,
                    toast: true,
                    position: 'top-end'
                });
                document.getElementById('barcodeSearch').value = '';
            }
        });
    }, 300);
}

// بحث عن الأصناف
function searchItems() {
    const searchTerm = document.getElementById('searchItems').value.trim().toLowerCase();
    
    if (searchTerm === '') {
        hideItems();
        return;
    }
    
    if (searchTerm.length < 2) {
        return;
    }
    
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(function() {
        document.getElementById('itemsGrid').innerHTML = `
            <div class="col-12 text-center py-3">
                <div class="spinner-border spinner-border-sm" style="color: var(--primary-navy);" role="status">
                    <span class="visually-hidden">جاري البحث...</span>
                </div>
            </div>
        `;
        
        document.getElementById('itemsContainer').classList.add('show');
        document.getElementById('noItemsMessage').style.display = 'none';
        
        $.ajax({
            url: 'ajax/search_items.php',
            type: 'GET',
            data: { search: searchTerm },
            dataType: 'json',
            success: function(data) {
                if (data.success && data.items.length > 0) {
                    allItems = data.items;
                    displayItems(data.items);
                } else {
                    document.getElementById('itemsGrid').innerHTML = `
                        <div class="col-12 text-center py-5">
                            <i class="fas fa-search fa-3x mb-3" style="color: var(--soft-gray);"></i>
                            <h5>لا توجد نتائج للبحث</h5>
                            <p class="text-muted">جرب كلمة بحث أخرى</p>
                        </div>
                    `;
                }
            },
            error: function(xhr, status, error) {
                console.error('Search Error:', error);
                console.error('Status:', status);
                console.error('Response Text:', xhr.responseText);
                console.error('Response Status:', xhr.status);
                
                let errorMsg = 'حدث خطأ في البحث';
                if (xhr.responseText) {
                    errorMsg += '<br><small class="text-muted">' + xhr.responseText.substring(0, 200) + '</small>';
                }
                
                document.getElementById('itemsGrid').innerHTML = `
                    <div class="col-12 text-center py-5 text-danger">
                        <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                        <h5>${errorMsg}</h5>
                    </div>
                `;
            }
        });
    }, 300);
}

// تحميل أصناف المجموعة
function loadCategoryItems(categoryId) {
    document.querySelectorAll('.category-card').forEach(card => {
        card.classList.remove('active');
    });
    
    document.querySelector(`[data-category="${categoryId}"]`).classList.add('active');
    
    document.getElementById('itemsGrid').innerHTML = `
        <div class="col-12 text-center py-5">
            <div class="spinner-border" style="color: var(--primary-navy);" role="status">
                <span class="visually-hidden">جاري التحميل...</span>
            </div>
            <p class="mt-2">جاري تحميل الأصناف...</p>
        </div>
    `;
    
    document.getElementById('itemsContainer').classList.add('show');
    document.getElementById('noItemsMessage').style.display = 'none';
    
    fetch(`ajax/get_category_items.php?category_id=${categoryId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayItems(data.items);
            } else {
                document.getElementById('itemsGrid').innerHTML = `
                    <div class="col-12 text-center py-5">
                        <i class="fas fa-exclamation-circle fa-3x mb-3" style="color: var(--soft-gray);"></i>
                        <h5>لا توجد أصناف في هذه المجموعة</h5>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('itemsGrid').innerHTML = `
                <div class="col-12 text-center py-5 text-danger">
                    <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                    <h5>حدث خطأ في تحميل الأصناف</h5>
                </div>
            `;
        });
}

// عرض الأصناف
function displayItems(items) {
    const itemsGrid = document.getElementById('itemsGrid');
    
    if (items.length === 0) {
        itemsGrid.innerHTML = `
            <div class="col-12 text-center py-5">
                <i class="fas fa-box-open fa-3x mb-3" style="color: var(--soft-gray);"></i>
                <h5>لا توجد أصناف في هذه المجموعة</h5>
            </div>
        `;
        return;
    }
    
    let html = '';
    items.forEach(item => {
        html += `
            <div class="col-lg-2 col-md-3 col-sm-4 col-6 mb-2">
                <div class="item-card" onclick="addItemToOrder(${item.id}, '${item.name}', ${item.price})">
                    <div class="item-image">
                        <i class="fas fa-tshirt" style="color: var(--soft-gray);"></i>
                    </div>
                    <div class="item-details">
                        <div class="item-name">${item.name}</div>
                        <div class="item-price">${parseFloat(item.price).toFixed(2)} ج.م</div>
                    </div>
                </div>
            </div>
        `;
    });
    
    itemsGrid.innerHTML = html;
}

// إضافة صنف للطلب
function addItemToOrder(itemId, itemName, itemPrice) {
    const existingItemIndex = selectedItems.findIndex(item => item.id === itemId);
    
    if (existingItemIndex !== -1) {
        selectedItems[existingItemIndex].quantity += 1;
    } else {
        selectedItems.push({
            id: itemId,
            name: itemName,
            price: parseFloat(itemPrice),
            quantity: 1
        });
    }
    
    updateOrderDisplay();
    
    Swal.fire({
        icon: 'success',
        title: 'تم الإضافة',
        text: `تم إضافة ${itemName} للطلب`,
        timer: 1000,
        showConfirmButton: false,
        toast: true,
        position: 'top-end'
    });
}

// تحديث عرض الطلب
function updateOrderDisplay() {
    const itemData = document.getElementById('itemData');
    const itemCount = document.getElementById('itemCount');
    
    if (selectedItems.length === 0) {
        itemData.innerHTML = '<p class="text-muted text-center" style="font-size: 0.8rem;">لا توجد أصناف</p>';
        itemCount.textContent = '0';
        updateTotals();
        return;
    }
    
    let html = '';
    selectedItems.forEach((item, index) => {
        const subtotal = item.quantity * item.price;
        html += `
            <div class="order-item">
                <div class="flex-grow-1">
                    <div class="fw-bold" style="font-size: 0.75rem;">${item.name}</div>
                    <small class="text-muted" style="font-size: 0.65rem;">${item.price.toFixed(2)} × ${item.quantity}</small>
                </div>
                <div class="text-end">
                    <div class="fw-bold" style="color: var(--primary-violet); font-size: 0.75rem;">${subtotal.toFixed(2)}</div>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="decreaseQuantity(${index})" style="padding: 0.1rem 0.3rem; font-size: 0.7rem;">
                            <i class="fas fa-minus"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="increaseQuantity(${index})" style="padding: 0.1rem 0.3rem; font-size: 0.7rem;">
                            <i class="fas fa-plus"></i>
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeItem(${index})" style="padding: 0.1rem 0.3rem; font-size: 0.7rem;">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                <input type="hidden" name="itmname[]" value="${item.id}">
                <input type="hidden" name="itmqty[]" value="${item.quantity}">
                <input type="hidden" name="itmprice[]" value="${item.price}">
                <input type="hidden" name="itmdisc[]" value="0">
                <input type="hidden" name="u_val[]" value="1">
                <input type="hidden" name="itmval[]" value="${subtotal.toFixed(2)}">
            </div>
        `;
    });
    
    itemData.innerHTML = html;
    itemCount.textContent = selectedItems.length;
    updateTotals();
}

function increaseQuantity(index) {
    selectedItems[index].quantity += 1;
    updateOrderDisplay();
}

function decreaseQuantity(index) {
    if (selectedItems[index].quantity > 1) {
        selectedItems[index].quantity -= 1;
        updateOrderDisplay();
    }
}

function removeItem(index) {
    selectedItems.splice(index, 1);
    updateOrderDisplay();
}

function clearItems() {
    selectedItems = [];
    document.getElementById('itemData').innerHTML = '<p class="text-muted text-center" style="font-size: 0.8rem;">لا توجد أصناف</p>';
    document.getElementById('itemCount').textContent = '0';
    updateTotals();
}

function updateTotals() {
    let total = 0;
    selectedItems.forEach(item => {
        total += item.quantity * item.price;
    });
    
    const discount = parseFloat(document.getElementById('modal_discount')?.value || 0);
    const net = total - discount;
    
    document.getElementById('total_display').textContent = total.toFixed(2) + ' ج.م';
    document.getElementById('net_display').textContent = net.toFixed(2) + ' ج.م';
    document.getElementById('total_display_btn').textContent = net.toFixed(2) + ' ج.م';
    
    document.getElementById('total').value = total.toFixed(2);
    document.getElementById('net_val').value = net.toFixed(2);
    
    if (document.getElementById('modal_total')) {
        document.getElementById('modal_total').textContent = total.toFixed(2) + ' ج.م';
        document.getElementById('modal_net').textContent = net.toFixed(2) + ' ج.م';
        document.getElementById('modal_paid').value = net.toFixed(2);
        updateChange();
    }
}

function hideItems() {
    document.getElementById('itemsContainer').classList.remove('show');
    document.getElementById('noItemsMessage').style.display = 'block';
    
    document.querySelectorAll('.category-card').forEach(card => {
        card.classList.remove('active');
    });
}

function updateChange() {
    const net = parseFloat(document.getElementById('modal_net').textContent.replace(' ج.م', '')) || 0;
    const paid = parseFloat(document.getElementById('modal_paid').value) || 0;
    const change = paid - net;
    
    document.getElementById('modal_change').value = change.toFixed(2);
    
    if (change < 0) {
        document.getElementById('modal_change').className = 'form-control form-control-lg text-center fw-bold bg-danger text-white';
    } else {
        document.getElementById('modal_change').className = 'form-control form-control-lg text-center fw-bold bg-success text-white';
    }
}

function updateDiscount() {
    const total = parseFloat(document.getElementById('total').value) || 0;
    const discountPercent = parseFloat(document.getElementById('modal_discperc').value) || 0;
    const discountValue = parseFloat(document.getElementById('modal_discount').value) || 0;
    
    let finalDiscount = discountValue;
    
    if (discountPercent > 0) {
        finalDiscount = (total * discountPercent) / 100;
        document.getElementById('modal_discount').value = finalDiscount.toFixed(2);
    }
    
    document.getElementById('discount').value = finalDiscount.toFixed(2);
    updateTotals();
}

function submitPOS(action) {
    if (selectedItems.length === 0) {
        alert('يجب إضافة صنف واحد على الأقل');
        return false;
    }
    
    const form = document.getElementById('posForm');
    
    // إضافة نوع العملية
    let submitInput = form.querySelector('input[name="submit"]');
    if (!submitInput) {
        submitInput = document.createElement('input');
        submitInput.type = 'hidden';
        submitInput.name = 'submit';
        form.appendChild(submitInput);
    }
    submitInput.value = action;
    
    // إضافة المبلغ المدفوع
    let paidInput = form.querySelector('input[name="paid"]');
    if (!paidInput) {
        paidInput = document.createElement('input');
        paidInput.type = 'hidden';
        paidInput.name = 'paid';
        form.appendChild(paidInput);
    }
    paidInput.value = document.getElementById('modal_paid').value;
    
    // إضافة الصندوق من المودال
    let fundInput = form.querySelector('input[name="fund_id"]');
    if (!fundInput) {
        fundInput = document.createElement('input');
        fundInput.type = 'hidden';
        fundInput.name = 'fund_id';
        form.appendChild(fundInput);
    }
    const fundSelect = document.querySelector('#paymentModal select[name="fund_id"]');
    fundInput.value = fundSelect ? fundSelect.value : '';
    
    $('#paymentModal').modal('hide');
    
    setTimeout(function() {
        HTMLFormElement.prototype.submit.call(form);
    }, 500);
    
    return true;
}

// تبديل وضع الشاشة الكاملة
function toggleFullscreen() {
    if (!document.fullscreenElement && !document.webkitFullscreenElement && 
        !document.mozFullScreenElement && !document.msFullscreenElement) {
        const elem = document.documentElement;
        if (elem.requestFullscreen) {
            elem.requestFullscreen();
        } else if (elem.webkitRequestFullscreen) {
            elem.webkitRequestFullscreen();
        } else if (elem.mozRequestFullScreen) {
            elem.mozRequestFullScreen();
        } else if (elem.msRequestFullscreen) {
            elem.msRequestFullscreen();
        }
    } else {
        if (document.exitFullscreen) {
            document.exitFullscreen();
        } else if (document.webkitExitFullscreen) {
            document.webkitExitFullscreen();
        } else if (document.mozCancelFullScreen) {
            document.mozCancelFullScreen();
        } else if (document.msExitFullscreen) {
            document.msExitFullscreen();
        }
    }
}

function updateFullscreenIcon() {
    const icon = document.getElementById('fullscreenIcon');
    if (document.fullscreenElement || document.webkitFullscreenElement || 
        document.mozFullScreenElement || document.msFullscreenElement) {
        icon.className = 'fas fa-compress';
    } else {
        icon.className = 'fas fa-expand';
    }
}

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('noItemsMessage').style.display = 'block';
    
    // بحث بالباركود
    document.getElementById('barcodeSearch')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchByBarcode();
        }
    });
    
    // بحث عن الأصناف
    document.getElementById('searchItems')?.addEventListener('input', function(e) {
        searchItems();
    });
    
    document.getElementById('searchItems')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            searchItems();
        }
    });
    
    // مودال الدفع
    document.getElementById('modal_discperc')?.addEventListener('input', updateDiscount);
    document.getElementById('modal_discount')?.addEventListener('input', updateDiscount);
    document.getElementById('modal_paid')?.addEventListener('input', updateChange);
    
    $('#paymentModal').on('show.bs.modal', function() {
        updateTotals();
    });
    
    // تحديث أيقونة الشاشة الكاملة
    document.addEventListener('fullscreenchange', updateFullscreenIcon);
    document.addEventListener('webkitfullscreenchange', updateFullscreenIcon);
    document.addEventListener('mozfullscreenchange', updateFullscreenIcon);
    document.addEventListener('MSFullscreenChange', updateFullscreenIcon);
});
