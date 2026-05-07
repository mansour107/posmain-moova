/**
 * نظام القفل التلقائي لـ POS بعد الطباعة
 * Auto-lock POS system after printing
 */

(function() {
    'use strict';
    
    console.log('🔒 POS Auto-lock script loaded');
    
    // دالة قفل النظام
    function lockPOSSystem() {
        console.log('🔒 Locking POS system...');
        
        const overlay = document.getElementById('posPasswordOverlay');
        if (!overlay) {
            console.log('❌ Overlay not found, cannot lock');
            return false;
        }
        
        // مسح session storage
        sessionStorage.removeItem('pos_authenticated');
        sessionStorage.removeItem('pos_user_name');
        sessionStorage.removeItem('pos_user_id');
        
        // إظهار الـ overlay
        overlay.style.display = 'flex';
        
        // مسح حقل الإدخال
        const input = document.getElementById('posPasswordInput');
        const errorDiv = document.getElementById('posPasswordError');
        
        if (input) {
            input.value = '';
            input.focus();
        }
        
        if (errorDiv) {
            errorDiv.style.display = 'none';
        }
        
        console.log('✅ POS system locked successfully!');
        return true;
    }
    
    // دالة للتحقق من الـ authentication
    function checkAuthentication() {
        console.log('🔍 Checking authentication...');
        
        const isAuthenticated = sessionStorage.getItem('pos_authenticated');
        console.log('Authentication status:', isAuthenticated);
        
        if (isAuthenticated !== 'true') {
            console.log('❌ Not authenticated, need to lock');
            
            // انتظر حتى يتم تحميل الـ overlay
            const checkOverlay = setInterval(function() {
                const overlay = document.getElementById('posPasswordOverlay');
                if (overlay) {
                    clearInterval(checkOverlay);
                    lockPOSSystem();
                }
            }, 100);
            
            // إيقاف المحاولة بعد 3 ثواني
            setTimeout(function() {
                clearInterval(checkOverlay);
            }, 3000);
        } else {
            console.log('✅ Already authenticated');
        }
    }
    
    // مسح الـ authentication عند مغادرة الصفحة
    window.addEventListener('beforeunload', function() {
        console.log('👋 Leaving POS page, clearing authentication');
        sessionStorage.removeItem('pos_authenticated');
        sessionStorage.removeItem('pos_user_name');
        sessionStorage.removeItem('pos_user_id');
    });
    
    // مسح الـ authentication عند إخفاء الصفحة (تبديل التاب)
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            console.log('👁️ Page hidden, clearing authentication');
            sessionStorage.removeItem('pos_authenticated');
            sessionStorage.removeItem('pos_user_name');
            sessionStorage.removeItem('pos_user_id');
        } else {
            console.log('👁️ Page visible again, checking authentication');
            checkAuthentication();
        }
    });
    
    // مسح الـ authentication عند فقدان التركيز
    window.addEventListener('blur', function() {
        console.log('🔄 Window lost focus, clearing authentication');
        sessionStorage.removeItem('pos_authenticated');
        sessionStorage.removeItem('pos_user_name');
        sessionStorage.removeItem('pos_user_id');
    });
    
    // التحقق عند استعادة التركيز
    window.addEventListener('focus', function() {
        console.log('🔄 Window gained focus, checking authentication');
        checkAuthentication();
    });
    
    // التحقق عند تحميل الصفحة
    console.log('📄 Page loaded, checking authentication...');
    checkAuthentication();
    
    console.log('✅ POS Auto-lock system initialized');
})();

})();
