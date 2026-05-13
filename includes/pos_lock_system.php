<!-- نظام القفل البسيط - يعمل عند مغادرة صفحة إنشاء طلب POS -->
<script>
    // القفل عند مغادرة صفحة إنشاء طلب POS إلى أي صفحة أخرى
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a');
        if (link && link.href && 
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
