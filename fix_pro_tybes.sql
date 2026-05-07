-- إضافة أنواع فواتير المردود إلى جدول pro_tybes

-- التحقق من وجود السجلات أولاً وإضافتها إذا لم تكن موجودة
INSERT INTO `pro_tybes` (`id`, `pname`, `ptext`, `ptybe`, `info`) 
SELECT 9, 'فاتورة كاشير', NULL, 9, NULL
WHERE NOT EXISTS (SELECT 1 FROM `pro_tybes` WHERE `id` = 9);

INSERT INTO `pro_tybes` (`id`, `pname`, `ptext`, `ptybe`, `info`) 
SELECT 10, 'فاتورة مردود مشتريات', NULL, 10, NULL
WHERE NOT EXISTS (SELECT 1 FROM `pro_tybes` WHERE `id` = 10);

INSERT INTO `pro_tybes` (`id`, `pname`, `ptext`, `ptybe`, `info`) 
SELECT 11, 'فاتورة مردود مبيعات', NULL, 11, NULL
WHERE NOT EXISTS (SELECT 1 FROM `pro_tybes` WHERE `id` = 11);

INSERT INTO `pro_tybes` (`id`, `pname`, `ptext`, `ptybe`, `info`) 
SELECT 12, 'أمر شراء', NULL, 12, NULL
WHERE NOT EXISTS (SELECT 1 FROM `pro_tybes` WHERE `id` = 12);

INSERT INTO `pro_tybes` (`id`, `pname`, `ptext`, `ptybe`, `info`) 
SELECT 13, 'أمر بيع', NULL, 13, NULL
WHERE NOT EXISTS (SELECT 1 FROM `pro_tybes` WHERE `id` = 13);

INSERT INTO `pro_tybes` (`id`, `pname`, `ptext`, `ptybe`, `info`) 
SELECT 14, 'عرض سعر', NULL, 14, NULL
WHERE NOT EXISTS (SELECT 1 FROM `pro_tybes` WHERE `id` = 14);
