// Service Worker لنظام POS الحالي
const CACHE_NAME = 'pos-barcode-offline-v2';
const urlsToCache = [
    '/kody/pos_barcode.php',
    '/kody/assets/libs/bootstrap.min.css',
    '/kody/assets/libs/fontawesome.min.css',
    '/kody/assets/libs/bootstrap.bundle.min.js',
    '/kody/plugins/jquery/jquery.min.js',
    '/kody/js/pos_config_loader.js',
    '/kody/js/pos_barcode.js',
    '/kody/js/pos_offline_adapter.js',
    '/kody/dist/css/pos.css',
    '/kody/dist/css/pos_barcode.css',
    '/kody/dist/css/pos_search.css',
    '/kody/assets/libs/webfonts/fa-solid-900.woff2',
    '/kody/assets/libs/webfonts/fa-regular-400.woff2',
    '/kody/assets/libs/webfonts/fa-brands-400.woff2'
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
                    // في حالة عدم توفر الشبكة
                    if (event.request.destination === 'document') {
                        return caches.match('/kody/pos_barcode.php');
                    }
                    
                    // للطلبات الأخرى، إرجاع استجابة فارغة
                    return new Response('Offline', {
                        status: 503,
                        statusText: 'Service Unavailable'
                    });
                });
            })
    );
});