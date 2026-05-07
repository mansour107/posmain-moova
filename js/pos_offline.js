// نظام POS أوفلاين - JavaScript
class OfflinePOS {
    constructor() {
        this.items = [];
        this.cart = [];
        this.orders = [];
        this.isOnline = navigator.onLine;
        
        this.init();
        this.setupEventListeners();
        this.loadData();
        this.renderItems();
        this.updateConnectionStatus();
    }

    init() {
        // تسجيل Service Worker
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js')
                .then(reg => console.log('Service Worker registered'))
                .catch(err => console.log('Service Worker registration failed'));
        }

        // إعداد البيانات الافتراضية
        if (!localStorage.getItem('pos_items')) {
            this.setupDefaultItems();
        }
    }

    setupDefaultItems() {
        const defaultItems = [
            { id: 1, name: 'شاي', price: 5.00, barcode: '001' },
            { id: 2, name: 'قهوة', price: 8.00, barcode: '002' },
            { id: 3, name: 'عصير برتقال', price: 12.00, barcode: '003' },
            { id: 4, name: 'ساندويتش جبنة', price: 15.00, barcode: '004' },
            { id: 5, name: 'كيك شوكولاتة', price: 20.00, barcode: '005' },
            { id: 6, name: 'مياه معدنية', price: 3.00, barcode: '006' }
        ];
        
        localStorage.setItem('pos_items', JSON.stringify(defaultItems));
        this.items = defaultItems;
    }

    setupEventListeners() {
        // مراقبة حالة الاتصال
        window.addEventListener('online', () => {
            this.isOnline = true;
            this.updateConnectionStatus();
            this.syncData();
        });

        window.addEventListener('offline', () => {
            this.isOnline = false;
            this.updateConnectionStatus();
        });

        // مراقبة تغييرات Local Storage
        window.addEventListener('storage', (e) => {
            if (e.key === 'pos_items' || e.key === 'pos_orders') {
                this.loadData();
                this.renderItems();
                this.updateOrdersDisplay();
            }
        });
    }

    loadData() {
        // تحميل الأصناف
        const savedItems = localStorage.getItem('pos_items');
        if (savedItems) {
            this.items = JSON.parse(savedItems);
        }

        // تحميل الطلبات
        const savedOrders = localStorage.getItem('pos_orders');
        if (savedOrders) {
            this.orders = JSON.parse(savedOrders);
        }

        this.updateOrdersDisplay();
    }

    saveData() {
        localStorage.setItem('pos_items', JSON.stringify(this.items));
        localStorage.setItem('pos_orders', JSON.stringify(this.orders));
        this.updateSyncStatus('محفوظ محلياً');
    }

    renderItems() {
        const grid = document.getElementById('itemsGrid');
        grid.innerHTML = '';

        this.items.forEach(item => {
            const itemCard = document.createElement('div');
            itemCard.className = 'col-md-4 col-sm-6';
            itemCard.innerHTML = `
                <div class="card item-card h-100" onclick="pos.addToCart(${item.id})">
                    <div class="card-body text-center">
                        <i class="fas fa-utensils fa-3x text-primary mb-2"></i>
                        <h6 class="card-title">${item.name}</h6>
                        <p class="card-text text-success fw-bold">${item.price.toFixed(2)} ج.م</p>
                        <small class="text-muted">كود: ${item.barcode}</small>
                    </div>
                </div>
            `;
            grid.appendChild(itemCard);
        });

        // إضافة بطاقة "إضافة صنف جديد"
        const addCard = document.createElement('div');
        addCard.className = 'col-md-4 col-sm-6';
        addCard.innerHTML = `
            <div class="card item-card h-100 border-dashed" data-bs-toggle="modal" data-bs-target="#addItemModal">
                <div class="card-body text-center d-flex flex-column justify-content-center">
                    <i class="fas fa-plus fa-3x text-muted mb-2"></i>
                    <h6 class="card-title text-muted">إضافة صنف جديد</h6>
                </div>
            </div>
        `;
        grid.appendChild(addCard);
    }

    addToCart(itemId) {
        const item = this.items.find(i => i.id === itemId);
        if (!item) return;

        const existingItem = this.cart.find(i => i.id === itemId);
        if (existingItem) {
            existingItem.quantity += 1;
        } else {
            this.cart.push({
                ...item,
                quantity: 1
            });
        }

        this.renderCart();
        this.updateTotal();
    }

    removeFromCart(itemId) {
        this.cart = this.cart.filter(item => item.id !== itemId);
        this.renderCart();
        this.updateTotal();
    }

    updateQuantity(itemId, quantity) {
        const item = this.cart.find(i => i.id === itemId);
        if (item) {
            item.quantity = Math.max(1, quantity);
            this.renderCart();
            this.updateTotal();
        }
    }

    renderCart() {
        const cartContainer = document.getElementById('cartItems');
        const cartCount = document.getElementById('cartCount');
        
        if (this.cart.length === 0) {
            cartContainer.innerHTML = '<p class="text-muted text-center">السلة فارغة</p>';
            cartCount.textContent = '0';
            document.getElementById('checkoutBtn').disabled = true;
            return;
        }

        cartCount.textContent = this.cart.reduce((sum, item) => sum + item.quantity, 0);
        document.getElementById('checkoutBtn').disabled = false;

        cartContainer.innerHTML = this.cart.map(item => `
            <div class="cart-item p-2 mb-2 bg-light rounded">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${item.name}</strong><br>
                        <small class="text-muted">${item.price.toFixed(2)} ج.م × ${item.quantity}</small>
                    </div>
                    <div class="d-flex align-items-center">
                        <button class="btn btn-sm btn-outline-secondary me-1" 
                                onclick="pos.updateQuantity(${item.id}, ${item.quantity - 1})">-</button>
                        <span class="mx-2">${item.quantity}</span>
                        <button class="btn btn-sm btn-outline-secondary me-2" 
                                onclick="pos.updateQuantity(${item.id}, ${item.quantity + 1})">+</button>
                        <button class="btn btn-sm btn-danger" 
                                onclick="pos.removeFromCart(${item.id})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        `).join('');
    }

    updateTotal() {
        const total = this.cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
        document.getElementById('totalAmount').textContent = total.toFixed(2) + ' ج.م';
    }

    processOrder() {
        if (this.cart.length === 0) return;

        const order = {
            id: Date.now(),
            items: [...this.cart],
            total: this.cart.reduce((sum, item) => sum + (item.price * item.quantity), 0),
            timestamp: new Date().toISOString(),
            synced: false
        };

        this.orders.push(order);
        this.cart = [];
        
        this.saveData();
        this.renderCart();
        this.updateTotal();
        this.updateOrdersDisplay();

        // إشعار نجاح
        this.showNotification('تم حفظ الطلب بنجاح!', 'success');

        // محاولة المزامنة إذا كان متصل
        if (this.isOnline) {
            this.syncData();
        }
    }

    updateOrdersDisplay() {
        const ordersContainer = document.getElementById('savedOrders');
        const ordersCount = document.getElementById('ordersCount');
        
        if (this.orders.length === 0) {
            ordersContainer.innerHTML = '<p class="text-muted text-center">لا توجد طلبات</p>';
            ordersCount.textContent = '0';
            return;
        }

        ordersCount.textContent = this.orders.length;
        
        const recentOrders = this.orders.slice(-5).reverse();
        ordersContainer.innerHTML = recentOrders.map(order => `
            <div class="border-bottom pb-2 mb-2">
                <div class="d-flex justify-content-between">
                    <small><strong>طلب #${order.id}</strong></small>
                    <small class="text-muted">${order.total.toFixed(2)} ج.م</small>
                </div>
                <small class="text-muted">
                    ${new Date(order.timestamp).toLocaleString('ar-EG')}
                    ${order.synced ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-clock text-warning"></i>'}
                </small>
            </div>
        `).join('');
    }

    addNewItem() {
        const name = document.getElementById('itemName').value.trim();
        const price = parseFloat(document.getElementById('itemPrice').value);
        const barcode = document.getElementById('itemBarcode').value.trim();

        if (!name || !price) {
            this.showNotification('يرجى ملء جميع الحقول المطلوبة', 'error');
            return;
        }

        const newItem = {
            id: Date.now(),
            name: name,
            price: price,
            barcode: barcode || Date.now().toString()
        };

        this.items.push(newItem);
        this.saveData();
        this.renderItems();

        // إغلاق المودال ومسح النموذج
        const modal = bootstrap.Modal.getInstance(document.getElementById('addItemModal'));
        modal.hide();
        document.getElementById('addItemForm').reset();

        this.showNotification('تم إضافة الصنف بنجاح!', 'success');
    }

    async syncData() {
        if (!this.isOnline) {
            this.showNotification('غير متصل بالإنترنت', 'warning');
            return;
        }

        this.updateSyncStatus('جاري المزامنة...');

        try {
            // محاولة إرسال الطلبات غير المتزامنة
            const unsyncedOrders = this.orders.filter(order => !order.synced);
            
            for (const order of unsyncedOrders) {
                // محاكاة إرسال البيانات للخادم
                await this.sendOrderToServer(order);
                order.synced = true;
            }

            this.saveData();
            this.updateOrdersDisplay();
            this.updateSyncStatus('تم التزامن');
            this.showNotification('تم تزامن البيانات بنجاح!', 'success');

        } catch (error) {
            console.error('Sync failed:', error);
            this.updateSyncStatus('فشل التزامن');
            this.showNotification('فشل في تزامن البيانات', 'error');
        }
    }

    async sendOrderToServer(order) {
        // محاكاة إرسال البيانات للخادم
        return new Promise((resolve, reject) => {
            setTimeout(() => {
                // محاكاة نجاح أو فشل العملية
                if (Math.random() > 0.1) { // 90% نجاح
                    resolve(order);
                } else {
                    reject(new Error('Server error'));
                }
            }, 1000);
        });
    }

    updateConnectionStatus() {
        const indicator = document.getElementById('connectionStatus');
        if (this.isOnline) {
            indicator.textContent = 'متصل';
            indicator.className = 'badge bg-success';
        } else {
            indicator.textContent = 'غير متصل';
            indicator.className = 'badge bg-danger';
        }
    }

    updateSyncStatus(status) {
        const indicator = document.getElementById('syncIndicator');
        indicator.textContent = status;
        
        if (status.includes('جاري')) {
            indicator.className = 'badge bg-warning';
        } else if (status.includes('تم')) {
            indicator.className = 'badge bg-success';
        } else if (status.includes('فشل')) {
            indicator.className = 'badge bg-danger';
        } else {
            indicator.className = 'badge bg-info';
        }
    }

    showNotification(message, type = 'info') {
        // إنشاء إشعار بسيط
        const notification = document.createElement('div');
        notification.className = `alert alert-${type === 'error' ? 'danger' : type} alert-dismissible fade show position-fixed`;
        notification.style.cssText = 'top: 70px; right: 20px; z-index: 9999; min-width: 300px;';
        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(notification);
        
        // إزالة الإشعار تلقائياً بعد 3 ثواني
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 3000);
    }

    clearCache() {
        if (confirm('هل أنت متأكد من مسح جميع البيانات المحفوظة؟')) {
            localStorage.removeItem('pos_items');
            localStorage.removeItem('pos_orders');
            this.cart = [];
            this.orders = [];
            this.setupDefaultItems();
            this.renderItems();
            this.renderCart();
            this.updateTotal();
            this.updateOrdersDisplay();
            this.showNotification('تم مسح جميع البيانات', 'info');
        }
    }
}

// تهيئة النظام
const pos = new OfflinePOS();

// دوال عامة
function syncData() {
    pos.syncData();
}

function clearCache() {
    pos.clearCache();
}

function addNewItem() {
    pos.addNewItem();
}