// محول النظام الحالي للعمل أوفلاين
class POSOfflineAdapter {
    constructor() {
        this.isOnline = navigator.onLine;
        this.offlineData = {
            items: [],
            orders: [],
            customers: [],
            tables: []
        };
        
        this.init();
    }

    init() {
        this.setupServiceWorker();
        this.loadOfflineData();
        this.setupEventListeners();
        this.interceptAjaxCalls();
        this.interceptFetchCalls();
        this.cacheCurrentItems();
    }

    setupServiceWorker() {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('pos_sw.js')
                .then(reg => console.log('POS Service Worker registered'))
                .catch(err => console.log('Service Worker registration failed'));
        }
    }

    loadOfflineData() {
        const savedItems = localStorage.getItem('pos_offline_items');
        if (savedItems) {
            this.offlineData.items = JSON.parse(savedItems);
        }

        const savedOrders = localStorage.getItem('pos_offline_orders');
        if (savedOrders) {
            this.offlineData.orders = JSON.parse(savedOrders);
        }

        const savedCustomers = localStorage.getItem('pos_offline_customers');
        if (savedCustomers) {
            this.offlineData.customers = JSON.parse(savedCustomers);
        }

        const savedTables = localStorage.getItem('pos_offline_tables');
        if (savedTables) {
            this.offlineData.tables = JSON.parse(savedTables);
        }
    }

    saveOfflineData() {
        localStorage.setItem('pos_offline_items', JSON.stringify(this.offlineData.items));
        localStorage.setItem('pos_offline_orders', JSON.stringify(this.offlineData.orders));
        localStorage.setItem('pos_offline_customers', JSON.stringify(this.offlineData.customers));
        localStorage.setItem('pos_offline_tables', JSON.stringify(this.offlineData.tables));
    }

    setupEventListeners() {
        window.addEventListener('online', () => {
            console.log('🌐 Network is back online - starting sync...');
            this.isOnline = true;
            this.updateConnectionStatus();
            // تأخير بسيط للتأكد من استقرار الاتصال
            setTimeout(() => {
                this.syncPendingData();
            }, 1000);
        });

        window.addEventListener('offline', () => {
            console.log('📴 Network went offline');
            this.isOnline = false;
            this.updateConnectionStatus();
        });
    }

    cacheCurrentItems() {
        const itemCards = document.querySelectorAll('.item-card');
        const items = [];
        
        itemCards.forEach(card => {
            const id = card.getAttribute('data-item-id');
            const name = card.getAttribute('data-item-name');
            const price = card.getAttribute('data-item-price');
            const barcode = card.getAttribute('data-item-barcode');
            
            if (id && name && price) {
                items.push({
                    id: parseInt(id),
                    name: name,
                    price: parseFloat(price),
                    barcode: barcode || id
                });
            }
        });
        
        if (items.length > 0) {
            this.offlineData.items = items;
            this.saveOfflineData();
        }
    }

    interceptAjaxCalls() {
        const originalAjax = $.ajax;
        const self = this;
        
        // حفظ المرجع الأصلي للاستخدام في المزامنة
        this.originalAjax = originalAjax;
        
        $.ajax = function(options, ajaxSettings) {
            const originalArgs = arguments;
            const requestOptions = self.normalizeAjaxOptions(options, ajaxSettings);
            console.log('🌐 AJAX Request:', requestOptions.url, 'Online:', self.isOnline);
            
            // السماح بالمرور للمزامنة
            if (requestOptions._bypassOffline) {
                return originalAjax.apply(this, originalArgs);
            }

            if (!self.isOnline && self.shouldTryLocalFirst(requestOptions)) {
                return self.tryLocalAjaxRequest(this, originalArgs, requestOptions);
            }
            
            if (!self.isOnline) {
                console.log('📴 Handling offline request');
                return self.handleOfflineRequest(requestOptions);
            }
            
            return originalAjax.apply(this, originalArgs).done(function(data) {
                self.cacheResponse(requestOptions.url, data);
            });
        };
    }

    interceptFetchCalls() {
        if (!window.fetch || window.__posOfflineFetchWrapped) {
            return;
        }

        const self = this;
        const originalFetch = window.fetch.bind(window);
        window.__posOfflineFetchWrapped = true;

        window.fetch = function(input, init) {
            const url = typeof input === 'string' ? input : (input && input.url ? input.url : '');
            const isPosOrderApi = url.indexOf('api/pos/index.php') !== -1;
            const method = String((init && init.method) || 'GET').toUpperCase();

            if (!self.isOnline && isPosOrderApi && method === 'POST') {
                return self.queueOfflineOrderFetch(url, init || {});
            }

            return originalFetch(input, init);
        };
    }

    queueOfflineOrderFetch(url, init) {
        let payload = {};
        try {
            payload = init.body ? JSON.parse(init.body) : {};
        } catch (error) {
            payload = {};
        }

        if (!payload.idempotency_key) {
            payload.idempotency_key = 'offline:' + Date.now().toString(36) + ':' + Math.random().toString(36).slice(2);
        }

        const queued = {
            url: url,
            payload: payload,
            queued_at: new Date().toISOString(),
            status: 'queued'
        };
        this.offlineData.orders.push(queued);
        this.saveOfflineData();
        this.showOfflineNotification('تم حفظ الطلب محلياً وسيتم إرساله عند عودة الاتصال');

        return Promise.resolve(new Response(JSON.stringify({
            success: true,
            code: 'OFFLINE_QUEUED',
            message: 'تم حفظ الطلب محلياً',
            request_id: payload.idempotency_key,
            offline_queued: true
        }), {
            status: 202,
            headers: { 'Content-Type': 'application/json' }
        }));
    }

    normalizeAjaxOptions(options, ajaxSettings) {
        if (typeof options === 'string') {
            return Object.assign({ url: options, method: 'GET' }, ajaxSettings || {});
        }

        return options || {};
    }

    shouldTryLocalFirst(options) {
        if (!options || !options.url) {
            return true;
        }

        const method = String(options.method || options.type || 'GET').toUpperCase();
        if (!['GET', 'POST'].includes(method)) {
            return false;
        }

        return this.isSameOriginRequest(options.url);
    }

    isSameOriginRequest(url) {
        try {
            const parsed = new URL(url, window.location.href);
            return parsed.origin === window.location.origin;
        } catch (error) {
            return !/^https?:\/\//i.test(String(url));
        }
    }

    tryLocalAjaxRequest(context, originalArgs, requestOptions) {
        const localRequest = this.originalAjax.apply(context, originalArgs);
        const deferred = $.Deferred();

        localRequest.done((data, textStatus, jqXHR) => {
            this.cacheResponse(requestOptions.url, data);
            deferred.resolveWith(context, [data, textStatus, jqXHR]);
        });

        localRequest.fail((jqXHR, textStatus, errorThrown) => {
            console.log('📴 Local request failed while offline - trying fallback:', requestOptions.url);
            this.handleOfflineRequest(requestOptions)
                .done((data, fallbackTextStatus, fallbackJqXHR) => {
                    deferred.resolveWith(context, [data, fallbackTextStatus, fallbackJqXHR]);
                })
                .fail(() => {
                    deferred.rejectWith(context, [jqXHR, textStatus, errorThrown]);
                });
        });

        return deferred.promise();
    }

    handleOfflineRequest(options) {
        const deferred = $.Deferred();
        
        if (options.url && options.url.includes('pos_customer_search.php')) {
            this.handleCustomerSearch(options, deferred);
        } else if (options.url && (options.url.includes('doadd_invoice.php') || options.url.includes('api/pos/index.php'))) {
            this.handleOrderSave(options, deferred);
        } else if (options.url && options.url.includes('get_tables.php')) {
            this.handleTablesRequest(deferred);
        } else if (options.url && options.url.includes('get_items.php')) {
            this.handleItemsRequest(deferred);
        } else {
            deferred.reject({
                status: 503,
                statusText: 'Service Unavailable - Offline Mode'
            });
        }
        
        return deferred.promise();
    }

    handleCustomerSearch(options, deferred) {
        const phone = options.data && (options.data.phone || options.data.q);
        const customer = this.offlineData.customers.find(c => c.phone.includes(phone));
        
        setTimeout(() => {
            if (customer) {
                deferred.resolve(JSON.stringify({
                    success: true,
                    exact: {
                        id: customer.id || 0,
                        display_name: customer.name,
                        primary_phone: customer.phone,
                        stats: { orders_count: 0, lifetime_paid: 0 },
                    },
                    found: true,
                    name: customer.name,
                    address: customer.address
                }));
            } else {
                deferred.resolve(JSON.stringify({
                    success: true,
                    exact: null,
                    suggestions: [],
                    found: false
                }));
            }
        }, 500);
    }

    handleOrderSave(options, deferred) {
        console.log('💾 Saving order offline...', options);
        
        const order = {
            id: Date.now(),
            timestamp: new Date().toISOString(),
            data: options.data,
            method: options.method || 'POST',
            url: options.url || 'api/pos/index.php?route=orders.takeaway',
            synced: false
        };
        
        this.offlineData.orders.push(order);
        this.saveOfflineData();
        
        console.log('✅ Order saved offline:', order.id);
        console.log('📊 Total offline orders:', this.offlineData.orders.length);
        
        setTimeout(() => {
            deferred.resolve('success');
            this.showOfflineNotification(`تم حفظ الطلب محلياً (#${order.id}) - سيتم إرساله عند الاتصال`);
        }, 500);
    }

    handleItemsRequest(deferred) {
        setTimeout(() => {
            deferred.resolve(JSON.stringify(this.offlineData.items));
        }, 300);
    }

    handleTablesRequest(deferred) {
        setTimeout(() => {
            deferred.resolve({
                success: true,
                tables: this.offlineData.tables || []
            });
        }, 100);
    }

    addOfflineIndicator() {
        const indicator = document.createElement('div');
        indicator.id = 'offline-indicator';
        indicator.style.cssText = `
            position: fixed;
            top: 70px;
            right: 20px;
            z-index: 9999;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            transition: all 0.3s ease;
        `;
        
        document.body.appendChild(indicator);
        this.updateConnectionStatus();
    }

    updateConnectionStatus() {
        const indicator = document.getElementById('offline-indicator');
        if (!indicator) return;
        
        if (this.isOnline) {
            indicator.textContent = '🟢 متصل';
            indicator.style.backgroundColor = '#28a745';
            indicator.style.color = 'white';
        } else {
            indicator.textContent = '🔴 غير متصل';
            indicator.style.backgroundColor = '#dc3545';
            indicator.style.color = 'white';
        }
    }

    showOfflineNotification(message) {
        const notification = document.createElement('div');
        notification.className = 'alert alert-warning alert-dismissible fade show';
        notification.style.cssText = `
            position: fixed;
            top: 120px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            max-width: 400px;
        `;
        notification.innerHTML = `
            <i class="fas fa-wifi-slash me-2"></i>
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 5000);
    }

    async syncPendingData() {
        const pendingOrders = this.offlineData.orders.filter(function(order) {
            return order && !order.synced && order.status !== 'synced';
        });

        if (pendingOrders.length === 0) {
            console.log('✅ No pending orders to sync');
            return;
        }

        console.log(`🔄 Starting sync of ${pendingOrders.length} pending orders...`);
        this.showOfflineNotification(`جاري مزامنة ${pendingOrders.length} طلب معلق...`);

        let syncedCount = 0;
        let failedCount = 0;

        for (const order of pendingOrders) {
            try {
                if (order.payload && order.url) {
                    const response = await window.fetch(order.url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify(order.payload)
                    });
                    const body = await response.json();
                    if (!response.ok || body.success === false) {
                        throw new Error(body.message || 'sync_failed');
                    }
                } else {
                    const response = await new Promise((resolve, reject) => {
                        $.ajax({
                            url: order.url || 'api/pos/index.php?route=orders.takeaway',
                            method: order.method || 'POST',
                            data: order.data,
                            timeout: 15000,
                            success: resolve,
                            error: reject
                        });
                    });
                    console.log('📝 Response:', response);
                }

                order.synced = true;
                order.status = 'synced';
                order.syncedAt = new Date().toISOString();
                syncedCount++;
            } catch (error) {
                console.error('❌ Failed to sync queued order:', error);
                order.syncError = error.message || 'Unknown error';
                order.lastSyncAttempt = new Date().toISOString();
                failedCount++;
            }
        }

        this.saveOfflineData();

        if (syncedCount > 0) {
            this.showOfflineNotification(`✅ تم مزامنة ${syncedCount} طلب بنجاح!`);
        }

        if (failedCount > 0) {
            this.showOfflineNotification(`⚠️ فشل في مزامنة ${failedCount} طلب - سيتم إعادة المحاولة`);
        }

        console.log(`📊 Sync completed: ${syncedCount} success, ${failedCount} failed`);
    }

    saveCustomerOffline(phone, name, address) {
        const customer = {
            phone: phone,
            name: name,
            address: address
        };
        
        this.offlineData.customers.push(customer);
        this.saveOfflineData();
    }

    cacheResponse(url, data) {
        if (url && url.includes('get_tables.php') && data && data.success && Array.isArray(data.tables)) {
            this.offlineData.tables = data.tables;
            this.saveOfflineData();
        }

        if (url && url.includes('get_items.php') && Array.isArray(data)) {
            this.offlineData.items = data;
            this.saveOfflineData();
        }

        console.log('Caching response for:', url);
    }

    showPendingOrders() {
        const pendingOrders = this.offlineData.orders.filter(order => !order.synced);
        
        if (pendingOrders.length === 0) {
            alert('لا توجد طلبات معلقة');
            return;
        }
        
        let message = `الطلبات المعلقة (${pendingOrders.length}):\n\n`;
        pendingOrders.forEach((order, index) => {
            const date = new Date(order.timestamp).toLocaleString('ar-EG');
            message += `${index + 1}. طلب #${order.id}\n`;
            message += `   التاريخ: ${date}\n`;
            if (order.syncError) {
                message += `   خطأ: ${order.syncError}\n`;
            }
            message += '\n';
        });
        
        alert(message);
    }

    clearSyncedOrders() {
        const beforeCount = this.offlineData.orders.length;
        this.offlineData.orders = this.offlineData.orders.filter(order => !order.synced);
        const afterCount = this.offlineData.orders.length;
        const removedCount = beforeCount - afterCount;
        
        if (removedCount > 0) {
            this.saveOfflineData();
            console.log(`🗑️ Removed ${removedCount} synced orders`);
            alert(`تم مسح ${removedCount} طلب متزامن`);
        } else {
            alert('لا توجد طلبات متزامنة لمسحها');
        }
    }
}

// تفعيل النظام الأوفلاين
$(document).ready(function() {
    console.log('🔄 Initializing POS Offline Adapter...');
    window.posOfflineAdapter = new POSOfflineAdapter();
    console.log('✅ POS Offline Adapter initialized');
    
});
