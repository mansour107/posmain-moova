// Service Worker لنظام POS أوفلاين
const CACHE_NAME = 'pos-offline-v1';
const urlsToCache = [
    '/',
    '/pos_offline.html',
    '/assets/libs/bootstrap.min.css',
    '/assets/libs/fontawesome.min.css',
    '/assets/libs/bootstrap.bundle.min.js',
    '/js/pos_offline.js',
    '/assets/libs/webfonts/fa-solid-900.woff2',
    '/assets/libs/webfonts/fa-regular-400.woff2',
    '/assets/libs/webfonts/fa-brands-400.woff2'
];

// تثبيت Service Worker
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                console.log('Opened cache');
                return cache.addAll(urlsToCache);
            })
    );
});

// تفعيل Service Worker
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (cacheName !== CACHE_NAME) {
                        console.log('Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
});

// اعتراض الطلبات
self.addEventListener('fetch', event => {
    event.respondWith(
        caches.match(event.request)
            .then(response => {
                // إرجاع الملف من الكاش إذا وُجد
                if (response) {
                    return response;
                }

                // محاولة جلب الملف من الشبكة
                return fetch(event.request).then(response => {
                    // التحقق من صحة الاستجابة
                    if (!response || response.status !== 200 || response.type !== 'basic') {
                        return response;
                    }

                    // نسخ الاستجابة لحفظها في الكاش
                    const responseToCache = response.clone();

                    caches.open(CACHE_NAME)
                        .then(cache => {
                            cache.put(event.request, responseToCache);
                        });

                    return response;
                }).catch(() => {
                    // في حالة عدم توفر الشبكة، إرجاع صفحة أوفلاين
                    if (event.request.destination === 'document') {
                        return caches.match('/pos_offline.html');
                    }
                });
            })
    );
});

// مزامنة البيانات في الخلفية
self.addEventListener('sync', event => {
    if (event.tag === 'background-sync') {
        event.waitUntil(syncPendingOrders());
    }
});

async function syncPendingOrders() {
    try {
        // جلب الطلبات المعلقة من IndexedDB أو localStorage
        const orders = JSON.parse(localStorage.getItem('pos_orders') || '[]');
        const unsyncedOrders = orders.filter(order => !order.synced);

        for (const order of unsyncedOrders) {
            try {
                // محاولة إرسال الطلب للخادم
                const response = await fetch('/api/orders', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(order)
                });

                if (response.ok) {
                    // تحديث حالة الطلب
                    order.synced = true;
                }
            } catch (error) {
                console.error('Failed to sync order:', order.id, error);
            }
        }

        // حفظ التحديثات
        localStorage.setItem('pos_orders', JSON.stringify(orders));
        
        // إشعار العميل بالتحديث
        self.clients.matchAll().then(clients => {
            clients.forEach(client => {
                client.postMessage({
                    type: 'SYNC_COMPLETE',
                    syncedCount: unsyncedOrders.filter(o => o.synced).length
                });
            });
        });

    } catch (error) {
        console.error('Background sync failed:', error);
    }
}