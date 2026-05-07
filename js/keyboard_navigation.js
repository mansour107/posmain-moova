/**
 * نظام التنقل الكامل بالكيبورد في الفاتورة
 * Arrow Keys Navigation System
 */

(function() {
    'use strict';
    
    // تعريف جميع الحقول القابلة للتنقل
    const NAVIGABLE_SELECTORS = [
        '#itemSearchInput',
        'select[name="u_val[]"]',
        '#itmqty',
        '#itmprice',
        '#itmdisc',
        '#addRow',
        '#headdisc',
        '#headplus',
        '#paid',
        'select[name="fund_id"]',
        '#info',
        '#submit',
        '#submit2'
    ];
    
    let navigableElements = [];
    let currentFocusIndex = -1;
    
    /**
     * تحديث قائمة العناصر القابلة للتنقل
     */
    function updateNavigableElements() {
        navigableElements = [];
        
        NAVIGABLE_SELECTORS.forEach(selector => {
            const elements = document.querySelectorAll(selector);
            elements.forEach(el => {
                if (el && !el.disabled && !el.readOnly && el.offsetParent !== null) {
                    navigableElements.push(el);
                }
            });
        });
        
        console.log('Navigable elements updated:', navigableElements.length);
    }
    
    /**
     * الحصول على الفهرس الحالي للعنصر النشط
     */
    function getCurrentIndex() {
        const activeElement = document.activeElement;
        return navigableElements.indexOf(activeElement);
    }
    
    /**
     * التنقل للعنصر التالي
     */
    function navigateDown() {
        currentFocusIndex = getCurrentIndex();
        
        if (currentFocusIndex < navigableElements.length - 1) {
            currentFocusIndex++;
            focusElement(currentFocusIndex);
        }
    }
    
    /**
     * التنقل للعنصر السابق
     */
    function navigateUp() {
        currentFocusIndex = getCurrentIndex();
        
        if (currentFocusIndex > 0) {
            currentFocusIndex--;
            focusElement(currentFocusIndex);
        }
    }
    
    /**
     * التنقل لليمين (في نفس الصف)
     */
    function navigateRight() {
        const activeElement = document.activeElement;
        const currentRow = activeElement.closest('tr');
        
        if (!currentRow) {
            navigateUp();
            return;
        }
        
        // الحصول على جميع الحقول في الصف (من اليمين لليسار)
        const inputs = Array.from(currentRow.querySelectorAll('input:not([readonly]):not([hidden]):not([type="hidden"]), select:not([disabled]), button'));
        const currentIndex = inputs.indexOf(activeElement);
        
        if (currentIndex > 0) {
            inputs[currentIndex - 1].focus();
            if (inputs[currentIndex - 1].select) {
                inputs[currentIndex - 1].select();
            }
        } else {
            // إذا وصلنا لأول عنصر، ننتقل للصف السابق
            const prevRow = currentRow.previousElementSibling;
            if (prevRow) {
                const prevInputs = Array.from(prevRow.querySelectorAll('input:not([readonly]):not([hidden]):not([type="hidden"]), select:not([disabled]), button'));
                if (prevInputs.length > 0) {
                    const lastInput = prevInputs[prevInputs.length - 1];
                    lastInput.focus();
                    if (lastInput.select) lastInput.select();
                }
            }
        }
    }
    
    /**
     * التنقل لليسار (في نفس الصف)
     */
    function navigateLeft() {
        const activeElement = document.activeElement;
        const currentRow = activeElement.closest('tr');
        
        if (!currentRow) {
            navigateDown();
            return;
        }
        
        // الحصول على جميع الحقول في الصف (من اليمين لليسار)
        const inputs = Array.from(currentRow.querySelectorAll('input:not([readonly]):not([hidden]):not([type="hidden"]), select:not([disabled]), button'));
        const currentIndex = inputs.indexOf(activeElement);
        
        if (currentIndex >= 0 && currentIndex < inputs.length - 1) {
            inputs[currentIndex + 1].focus();
            if (inputs[currentIndex + 1].select) {
                inputs[currentIndex + 1].select();
            }
        } else {
            // إذا وصلنا لآخر عنصر، ننتقل للصف التالي
            const nextRow = currentRow.nextElementSibling;
            if (nextRow) {
                const nextInputs = Array.from(nextRow.querySelectorAll('input:not([readonly]):not([hidden]):not([type="hidden"]), select:not([disabled]), button'));
                if (nextInputs.length > 0) {
                    nextInputs[0].focus();
                    if (nextInputs[0].select) nextInputs[0].select();
                }
            }
        }
    }
    
    /**
     * تركيز العنصر وتحديد النص
     */
    function focusElement(index) {
        if (index >= 0 && index < navigableElements.length) {
            const element = navigableElements[index];
            element.focus();
            
            // تحديد النص في حقول الإدخال
            if (element.tagName === 'INPUT' && element.type === 'text' || element.type === 'number') {
                element.select();
            }
        }
    }
    
    /**
     * معالج الأحداث الرئيسي للكيبورد
     */
    function handleKeyDown(e) {
        const activeElement = document.activeElement;
        const tagName = activeElement.tagName;
        
        // تجاهل إذا كان في textarea أو في وضع التعديل
        if (tagName === 'TEXTAREA') {
            return;
        }
        
        // معالجة الأسهم
        switch(e.key) {
            case 'ArrowUp':
                e.preventDefault();
                navigateUp();
                break;
                
            case 'ArrowRight':
                // السماح بالتنقل داخل النص إذا لم يكن في البداية
                if (tagName === 'INPUT' && activeElement.type === 'text') {
                    if (activeElement.selectionStart === 0) {
                        e.preventDefault();
                        navigateRight();
                    }
                } else {
                    e.preventDefault();
                    navigateRight();
                }
                break;
                
            case 'ArrowLeft':
                // السماح بالتنقل داخل النص إذا لم يكن في النهاية
                if (tagName === 'INPUT' && activeElement.type === 'text') {
                    if (activeElement.selectionStart === activeElement.value.length) {
                        e.preventDefault();
                        navigateLeft();
                    }
                } else {
                    e.preventDefault();
                    navigateLeft();
                }
                break;
                
            case 'Tab':
                // Tab للتنقل السريع بين الحقول
                if (e.shiftKey) {
                    // Shift+Tab للرجوع
                    e.preventDefault();
                    navigateUp();
                } else {
                    // Tab للتقدم
                    e.preventDefault();
                    navigateDown();
                }
                break;
                
            case 'Enter':
                // Enter للانتقال للحقل التالي أو تنفيذ الزر
                if (tagName === 'BUTTON') {
                    // السماح بالضغط على الزر
                    return;
                } else if (activeElement.id === 'itemSearchInput') {
                    // في حقل البحث، اختيار أول نتيجة
                    const firstResult = document.querySelector('.search-result-item');
                    if (firstResult) {
                        e.preventDefault();
                        firstResult.click();
                    }
                } else if (activeElement.id === 'addRow' || activeElement.closest('#addRow')) {
                    // السماح بإضافة الصف
                    return;
                } else {
                    e.preventDefault();
                    navigateDown();
                }
                break;
                
            case 'Escape':
                // Escape لإلغاء التحديد أو إغلاق القوائم
                if (activeElement.id === 'itemSearchInput') {
                    document.getElementById('searchResults').style.display = 'none';
                }
                activeElement.blur();
                break;
                
            // اختصارات F-Keys
            case 'F6':
                e.preventDefault();
                document.getElementById('headdisc')?.focus();
                document.getElementById('headdisc')?.select();
                break;
                
            case 'F7':
                e.preventDefault();
                document.getElementById('paid')?.focus();
                document.getElementById('paid')?.select();
                break;
                
            case 'F11':
                e.preventDefault();
                document.getElementById('submit2')?.click();
                break;
                
            case 'F12':
                e.preventDefault();
                document.getElementById('submit')?.click();
                break;
        }
        
        // اختصارات إضافية مع Alt (بدلاً من Ctrl لتجنب تعارض المتصفح)
        if (e.altKey && !e.ctrlKey && !e.shiftKey) {
            let handled = false;
            
            switch(e.key.toLowerCase()) {
                case 'n':
                    // Alt+N للانتقال لحقل اختيار الصنف
                    e.preventDefault();
                    const searchInput = document.getElementById('itemSearchInput');
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select();
                    }
                    handled = true;
                    break;
                    
                case 's':
                    // Alt+S للحفظ
                    e.preventDefault();
                    document.getElementById('submit')?.click();
                    handled = true;
                    break;
                    
                case 'p':
                    // Alt+P للحفظ والطباعة
                    e.preventDefault();
                    document.getElementById('submit2')?.click();
                    handled = true;
                    break;
                    
                case 'h':
                    // Alt+H لإظهار/إخفاء المساعدة
                    e.preventDefault();
                    const helpDiv = document.getElementById('keyboardHelp');
                    if (helpDiv) {
                        helpDiv.style.display = helpDiv.style.display === 'none' ? 'block' : 'none';
                    }
                    handled = true;
                    break;
            }
            
            if (handled) {
                e.stopPropagation();
                return false;
            }
        }
    }
    
    /**
     * تحسين تجربة البحث بالأسهم
     */
    function enhanceSearchResults() {
        const searchResults = document.getElementById('searchResults');
        if (!searchResults) return;
        
        let selectedResultIndex = -1;
        
        document.addEventListener('keydown', function(e) {
            if (document.activeElement.id !== 'itemSearchInput') return;
            
            const results = searchResults.querySelectorAll('.search-result-item');
            if (results.length === 0) return;
            
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedResultIndex = Math.min(selectedResultIndex + 1, results.length - 1);
                highlightResult(results, selectedResultIndex);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedResultIndex = Math.max(selectedResultIndex - 1, 0);
                highlightResult(results, selectedResultIndex);
            } else if (e.key === 'Enter' && selectedResultIndex >= 0) {
                e.preventDefault();
                results[selectedResultIndex].click();
                selectedResultIndex = -1;
            }
        });
        
        function highlightResult(results, index) {
            results.forEach((result, i) => {
                if (i === index) {
                    result.style.background = '#dbeafe';
                    result.scrollIntoView({ block: 'nearest' });
                } else {
                    result.style.background = 'white';
                }
            });
        }
    }
    
    /**
     * تهيئة النظام
     */
    function init() {
        console.log('Keyboard Navigation System Initialized');
        
        // تحديث العناصر عند تحميل الصفحة
        updateNavigableElements();
        
        // إضافة معالج الأحداث
        document.addEventListener('keydown', handleKeyDown);
        
        // تحسين نتائج البحث
        enhanceSearchResults();
        
        // تحديث العناصر عند إضافة صف جديد
        const addRowBtn = document.getElementById('addRow');
        if (addRowBtn) {
            addRowBtn.addEventListener('click', function() {
                setTimeout(updateNavigableElements, 100);
            });
        }
        
        // تحديث العناصر بشكل دوري (للصفوف الديناميكية)
        setInterval(updateNavigableElements, 2000);
        
        // تركيز حقل البحث عند التحميل
        setTimeout(() => {
            const searchInput = document.getElementById('itemSearchInput');
            if (searchInput) {
                searchInput.focus();
            }
        }, 500);
    }
    
    // تشغيل عند تحميل الصفحة
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    // إزالة المؤشر البصري للعنصر النشط (حسب طلب المستخدم)
    const style = document.createElement('style');
    style.textContent = `
        input:focus, select:focus, button:focus {
            outline: none !important;
            box-shadow: none !important;
        }
        
        .search-result-item:focus {
            background: #dbeafe !important;
            outline: none !important;
        }
    `;
    document.head.appendChild(style);
    
})();
