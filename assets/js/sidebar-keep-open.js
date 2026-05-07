// منع إغلاق السايد بار
// منع إغلاق السايد بار
document.addEventListener('DOMContentLoaded', function() {
    if (typeof jQuery === 'undefined') return;

    // منع إغلاق السايد بار عند الضغط على أي عنصر بداخله
    $('.main-sidebar').on('click', function(e) {
        // e.stopPropagation();
        $('body').removeClass('sidebar-collapse');
    });
    
    // منع إغلاق السايد بار عند الضغط على الروابط
    $('.main-sidebar .nav-link').on('click', function(e) {
        // e.stopPropagation();
        $('body').removeClass('sidebar-collapse');
    });
    
    // الحفاظ على السايد بار مفتوح دائماً على الشاشات الكبيرة
    if ($(window).width() > 991) {
        $('body').removeClass('sidebar-collapse');
    }
    
    // منع إغلاق السايد بار عند تغيير حجم النافذة
    $(window).resize(function() {
        if ($(window).width() > 991) {
            $('body').removeClass('sidebar-collapse');
        }
    });

    // تشغيل فوري
    $('body').removeClass('sidebar-collapse');
});