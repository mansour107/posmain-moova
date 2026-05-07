/**
 * تحسينات السايد بار - Sidebar Enhancements
 */

// تشغيل فوري للتأثيرات
(function() {
    // إضافة CSS ديناميكي لضمان عمل التأثيرات
    const style = document.createElement('style');
    style.textContent = `
        .main-sidebar .nav-link:hover {
            background: linear-gradient(135deg, #eff6ff, #dbeafe) !important;
            color: #1e40af !important;
            transform: translateX(3px) scale(1.02) !important;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25) !important;
            border-radius: 12px !important;
        }
    `;
    document.head.appendChild(style);
})();

document.addEventListener('DOMContentLoaded', function() {
    if (typeof jQuery === 'undefined') return;
    
    // تحسين تأثير الهوفر للعناصر الرئيسية
    $('.main-sidebar .nav-sidebar > .nav-item > .nav-link').hover(
        function() {
            $(this).addClass('hover-effect');
            $(this).find('.nav-icon').addClass('icon-bounce');
        },
        function() {
            $(this).removeClass('hover-effect');
            $(this).find('.nav-icon').removeClass('icon-bounce');
        }
    );
    
    // تحسين تأثير الهوفر للعناصر الفرعية
    $('.main-sidebar .nav-treeview .nav-link').hover(
        function() {
            $(this).addClass('submenu-hover');
            $(this).find('.nav-icon').addClass('slide-right');
        },
        function() {
            $(this).removeClass('submenu-hover');
            $(this).find('.nav-icon').removeClass('slide-right');
        }
    );
    
    // تأثير النقر على العناصر
    $('.main-sidebar .nav-link').click(function(e) {
        const $this = $(this);
        $this.addClass('click-effect');
        
        // Removed stopPropagation to allow AdminLTE to handle treeview expansion
        // e.stopPropagation();
        
        setTimeout(() => {
            $this.removeClass('click-effect');
        }, 200);
    });
    
    // منع إغلاق السايد بار عند الضغط على أي عنصر بداخله
    $('.main-sidebar').click(function(e) {
        e.stopPropagation();
        $('body').removeClass('sidebar-collapse');
    });
    
    // منع إغلاق السايد بار عند الضغط على القوائم الفرعية
    $('.main-sidebar .nav-treeview').click(function(e) {
        e.stopPropagation();
    });
    
    // تحسين البحث في السايد بار
    $('#searchSide').on('input', function() {
        const searchTerm = $(this).val().toLowerCase();
        const $navItems = $('.main-sidebar .nav-sidebar .nav-item');
        
        if (searchTerm === '') {
            $navItems.show();
            $('.main-sidebar .nav-treeview').hide();
            $('.main-sidebar .nav-item').removeClass('menu-open');
        } else {
            $navItems.each(function() {
                const $item = $(this);
                const itemText = $item.find('.nav-link p').text().toLowerCase();
                let hasMatch = itemText.includes(searchTerm);
                
                $item.find('.nav-treeview .nav-link p').each(function() {
                    if ($(this).text().toLowerCase().includes(searchTerm)) {
                        hasMatch = true;
                        $item.addClass('menu-open');
                        $item.find('.nav-treeview').show();
                    }
                });
                
                if (hasMatch) {
                    $item.show();
                } else {
                    $item.hide();
                }
            });
        }
    });
    
    // فرض تطبيق تأثيرات الهوفر بعد تحميل الصفحة
    setTimeout(function() {
        $('.main-sidebar .nav-link').each(function() {
            const $link = $(this);
            
            // إضافة تأثيرات مخصصة
            $link.on('mouseenter', function() {
                $(this).css({
                    'background': 'linear-gradient(135deg, #eff6ff, #dbeafe)',
                    'color': '#1e40af',
                    'transform': 'translateX(3px) scale(1.02)',
                    'box-shadow': '0 4px 12px rgba(59, 130, 246, 0.25)',
                    'border-radius': '12px'
                });
                
                $(this).find('.nav-icon').css({
                    'color': '#2563eb',
                    'transform': 'scale(1.15) rotate(5deg)'
                });
            });
            
            $link.on('mouseleave', function() {
                if (!$(this).hasClass('active')) {
                    $(this).css({
                        'background': '',
                        'color': '',
                        'transform': '',
                        'box-shadow': '',
                        'border-radius': ''
                    });
                    
                    $(this).find('.nav-icon').css({
                        'color': '',
                        'transform': ''
                    });
                }
            });
        });
        
        // إضافة حدث لمنع إغلاق السايد بار
        $('.main-sidebar .nav-link').off('click.keepOpen').on('click.keepOpen', function(e) {
            // Removed stopPropagation
            // e.stopPropagation();
            $('body').removeClass('sidebar-collapse');
        });
    }, 500);
    
    // تأثيرات إضافية للقوائم الفرعية
    $('.main-sidebar .nav-treeview .nav-link').on('mouseenter', function() {
        $(this).css({
            'background': 'linear-gradient(135deg, #f0f9ff, #e0f2fe)',
            'color': '#0369a1',
            'transform': 'translateX(5px) scale(1.02)',
            'box-shadow': '0 2px 8px rgba(3, 105, 161, 0.3)',
            'border-radius': '10px',
            'font-weight': '600'
        });
    }).on('mouseleave', function() {
        if (!$(this).hasClass('active')) {
            $(this).css({
                'background': '',
                'color': '',
                'transform': '',
                'box-shadow': '',
                'border-radius': '',
                'font-weight': ''
            });
        }
    });
});

// تشغيل فوري عند تحميل DOM
document.addEventListener('DOMContentLoaded', function() {
    // إضافة كلاس للتأكد من تحميل السايد بار
    const sidebar = document.querySelector('.main-sidebar');
    if (sidebar) {
        sidebar.classList.add('sidebar-loaded');
    }
});

// تشغيل فوري عند تحميل DOM
document.addEventListener('DOMContentLoaded', function() {
    // إضافة كلاس للتأكد من تحميل السايد بار
    const sidebar = document.querySelector('.main-sidebar');
    if (sidebar) {
        sidebar.classList.add('sidebar-loaded');
    }
});