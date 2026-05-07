<?php if(isset($rowstg['pos_has_password']) && $rowstg['pos_has_password'] == 1): ?>
<!-- نظام القفل البسيط - يعمل فقط عند تفعيل الحماية بالباركود -->
<script>
    // القفل عند تبديل التاب
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && sessionStorage.getItem('pos_hidden')) {
            sessionStorage.removeItem('pos_hidden');
            window.location.href = 'pos_barcode.php?logout=1';
        }
        if (document.hidden) {
            sessionStorage.setItem('pos_hidden', '1');
        }
    });
    
    // القفل عند الضغط على أي رابط غير tables.php و pos_barcode.php
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a');
        if (link && link.href && 
            !link.href.includes('tables.php') && 
            !link.href.includes('pos_barcode.php') && 
            link.target !== '_blank') {
            // قفل الجلسة قبل المغادرة
            sessionStorage.setItem('pos_locked', '1');
        }
    });
    
    // فحص عند تحميل الصفحة: لو راجع من صفحة تانية، اقفل
    if (sessionStorage.getItem('pos_locked') === '1') {
        sessionStorage.removeItem('pos_locked');
        window.location.href = 'pos_barcode.php?logout=1';
    }
    
    console.log('نظام القفل مفعل - الحماية بالباركود نشطة');
</script>
<?php endif; ?>
