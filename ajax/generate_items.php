<?php
// ajax/generate_items.php
session_start();
include(__DIR__ . '/../includes/connect.php');
require_once __DIR__ . '/../includes/production_guard.php';
require_once __DIR__ . '/../includes/auth_guard.php';

production_guard_deny_route('ajax/generate_items.php');
require_admin_or_permission('system.tools.run', $conn);

header('Content-Type: application/json');

// Ensure user is logged in
if (!isset($_SESSION['login']) || !isset($_SESSION['userid'])) {
    echo json_encode(['success' => false, 'message' => 'غير مصرح بالدخول. يرجى تسجيل الدخول أولاً.']);
    exit;
}

$action = $_POST['action'] ?? '';
if ($action !== 'generate') {
    echo json_encode(['success' => false, 'message' => 'طلب غير صالح.']);
    exit;
}

try {
    $count = isset($_POST['count']) ? (int)$_POST['count'] : 1000;
    $clearDb = isset($_POST['clear_db']) && $_POST['clear_db'] == 1;
    $clearGroups = isset($_POST['clear_groups']) && $_POST['clear_groups'] == 1;
    
    $chunk = isset($_POST['chunk']) ? (int)$_POST['chunk'] : 0;
    $chunkSize = isset($_POST['chunk_size']) ? (int)$_POST['chunk_size'] : 200;
    
    $userId = (int)$_SESSION['userid'];

    // 10 Default Categories with rich Arabic items dictionary
    $categories = [
        [
            'name' => 'مواد غذائية أساسية',
            'bases' => ['أرز مصري', 'سكر ناعم', 'زيت عباد الشمس', 'زيت ذرة', 'سمن بلدي', 'مكرونة قلم', 'مكرونة شعرية', 'ملح طعام', 'دقيق فاخر', 'عدس أصفر', 'فاصوليا جافة', 'لوبيا'],
            'brands' => ['الضحى', 'كريستال', 'عافية', 'روابي', 'الملكة', 'ريحانة', 'شهية', 'الساعة', 'جنه'],
            'sizes' => ['1 كيلو', '500 جرام', '800 مل', '1.5 لتر', '2 كيلو', 'علبة 700 جرام']
        ],
        [
            'name' => 'مشروبات',
            'bases' => ['شاي أحمر', 'شاي أخضر', 'قهوة تركي', 'قهوة أمريكي', 'عصير مانجو', 'عصير برتقال', 'عصير تفاح', 'عصير كوكتيل', 'مياه معدنية', 'مياه غازية بيبسي', 'مياه غازية كوكاكولا', 'كابتشينو', 'نسكافيه 3 في 1', 'ينسون', 'نعناع'],
            'brands' => ['ليبتون', 'العروسة', 'جهينة', 'المراعي', 'نستله', 'شاهين', 'كوكاكولا', 'سينا كولا', 'راني', 'بيتي', 'أحمد تي'],
            'sizes' => ['100 فتلة', '250 جرام', '1 لتر', '500 مل', 'عبوة عائلية', 'كانز 330 مل', 'علبة 20 فتلة']
        ],
        [
            'name' => 'ألبان وأجبان',
            'bases' => ['حليب كامل الدسم', 'حليب خالي الدسم', 'جبنة بيضاء فيتا', 'جبنة رومي قديمة', 'جبنة شيدر مستوردة', 'قشطة بلدي', 'زبادي طبيعي', 'لبنة تركية', 'زبدة صفراء', 'جبنة موزاريللا'],
            'brands' => ['جهينة', 'المراعي', 'قتيلو', 'دومتي', 'عبور لاند', 'مزارع دينا', 'نادك', 'الباندا', 'رولاند'],
            'sizes' => ['500 جرام', '1 كيلو', 'علبة 250 جرام', 'علبة 125 جرام', 'كوب', 'كيس 300 جرام']
        ],
        [
            'name' => 'معلبات',
            'bases' => ['تونة قطعة واحدة', 'تونة مفتتة', 'فول مدمس بالخلطة', 'صلصة طماطم', 'ذرة حلوة', 'فطر كامل', 'حمص حب', 'مايونيز', 'كاتشب حار', 'ورق عنب جاهز'],
            'brands' => ['هارفست', 'حدائق كاليفورنيا', 'هاينز', 'أمريكانا', 'العلالي', 'ريحانة', 'فاين فودز', 'دولفين', 'صن شاين'],
            'sizes' => ['140 جرام', '185 جرام', '400 جرام', 'برطمان 350 جرام', 'عبوة صغيرة', 'عبوة ضخمة']
        ],
        [
            'name' => 'مخبوزات',
            'bases' => ['خبز بلدي', 'خبز توست أبيض', 'خبز توست بني', 'كرواسون ساده', 'باتيه بالجبنة البيضاء', 'عيش فينو', 'كيك فانيليا', 'فطير مشلتت بلدي', 'بسكويت بالتمر'],
            'brands' => ['لوزين', 'ريتش بيك', 'الشرقية', 'مخابز القصر', 'بيمبو', 'مونجيني', 'تسيباس'],
            'sizes' => ['كيس 5 قطع', 'كيس 10 قطع', 'حبة واحدة', 'علبة كرتون', 'وزن نصف كيلو']
        ],
        [
            'name' => 'لحوم ومجمدات',
            'bases' => ['برجر بقري جامبو', 'سجق شرقي مميز', 'كفتة داوود باشا', 'بانيه دجاج مقرمش', 'شيش طاووق', 'خضار مشكل مجمد', 'ملوخية خضراء', 'بطاطس بوم فريت'],
            'brands' => ['أمريكانا', 'حلواني', 'السنبلة', 'كوكي', 'أطياب', 'المراعي', 'بسمة', 'جولدي'],
            'sizes' => ['400 جرام', '1 كيلو', 'كيس 2.5 كيلو', '8 قطع', '20 قطعة']
        ],
        [
            'name' => 'حلويات وتسالي',
            'bases' => ['شوكولاتة بالحليب واللوز', 'بسكويت ويفر بالشوكولاتة', 'شيبس طماطم ليز', 'شيبس ملح وخل', 'مقرمشات ذرة', 'جيلي فراولة', 'كيك شوكولاتة هوز', 'لب سوبر مملح'],
            'brands' => ['كادبوري', 'جالاكسي', 'شيبسي', 'تايجر', 'كرنشى', 'أولكر', 'بيمبو', 'سنيكرز', 'تودو'],
            'sizes' => ['قطعة واحدة', 'كيس صغير', 'كيس عائلي', 'علبة كرتون 12 قطعة']
        ],
        [
            'name' => 'منظفات وعناية',
            'bases' => ['مسحوق غسيل أوتوماتيك', 'صابون سائل للأطباق', 'مطهر عام', 'معطر جو سبراي', 'صابون كريمي لليدين', 'شامبو مغذي للشعر', 'بلسم منعم', 'معجون أسنان تبييض', 'مناديل ورقية معقمة'],
            'brands' => ['أريال', 'برسيل', 'تايد', 'فيري', 'ديتول', 'لوكس', 'بانتين', 'كلوز أب', 'فاين', 'دوني', 'كاماي'],
            'sizes' => ['1 لتر', '500 مل', '1 كيلو', '3 كيلو', 'قطعة 120 جرام', 'عبوة 3 حبات']
        ],
        [
            'name' => 'أدوات منزلية',
            'bases' => ['كوب زجاجي شاي', 'طبق ميلامين مسطح', 'ملاعق بلاستيك قوية', 'سكين مطبخ حاد', 'ليفة غسيل أطباق', 'أكياس قمامة سوداء', 'سلك ألومنيوم تنظيف'],
            'brands' => ['الهلال والنجمة', 'رويال للاستيراد', 'لومينارك الفرنسية', 'بلازا بلاست', 'فاميلي هوم'],
            'sizes' => ['طقم 6 قطع', 'حبة واحدة', 'كيس 50 حبة', 'لفة 10 أكياس']
        ],
        [
            'name' => 'خضروات وفواكه',
            'bases' => ['تفاح أحمر إيطالي', 'موز بلدي حلو', 'برتقال عصير أبو سرة', 'طماطم حمراء طازجة', 'بطاطس للطبخ والتحمير', 'بصل أحمر بلدي', 'خيار صوب مقرمش', 'ليمون بنزهير', 'ثوم فصوص كبير', 'فلفل أخضر رومي'],
            'brands' => ['مزارع بلدي', 'فاكهة مستوردة نخب أول', 'صوب زراعية محمية', 'فرز أول ممتاز'],
            'sizes' => ['1 كيلو جرام', 'علبة مغلفة 500 جرام', 'طبق فوم 250 جرام', 'حزمة']
        ]
    ];

    // Handle database clears ONLY on chunk 0
    if ($chunk === 0) {
        if ($clearDb) {
            $conn->query("SET FOREIGN_KEY_CHECKS = 0");
            $conn->query("TRUNCATE TABLE `myitems`");
            $conn->query("TRUNCATE TABLE `item_units`");
            $conn->query("TRUNCATE TABLE `imgs`");
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
            
            // Delete actual uploaded files to save disk space
            $files = glob('../uploads/*');
            foreach ($files as $file) {
                if (is_file($file) && basename($file) !== 'index.html') {
                    @unlink($file);
                }
            }
        }
        
        if ($clearGroups) {
            $conn->query("SET FOREIGN_KEY_CHECKS = 0");
            $conn->query("TRUNCATE TABLE `item_group`");
            $conn->query("TRUNCATE TABLE `item_group2`");
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
            
            // Re-insert default categories
            foreach ($categories as $index => $cat) {
                $stmt = $conn->prepare("INSERT INTO `item_group` (id, gname, isdeleted) VALUES (?, ?, 0)");
                $id = $index + 1;
                $stmt->bind_param("is", $id, $cat['name']);
                $stmt->execute();
                $stmt->close();
            }
        }
    }

    // Load active groups map
    $groupMap = [];
    $resGroups = $conn->query("SELECT id, gname FROM `item_group` WHERE isdeleted = 0");
    if ($resGroups) {
        while ($rg = $resGroups->fetch_assoc()) {
            $groupMap[$rg['gname']] = (int)$rg['id'];
        }
    }

    // Ensure we have at least one unit (e.g. piece with ID 1)
    $resUnits = $conn->query("SELECT id FROM `myunits` LIMIT 1");
    $defaultUnitId = 1;
    if ($resUnits && $resUnits->num_rows > 0) {
        $defaultUnitId = (int)$resUnits->fetch_assoc()['id'];
    } else {
        // Create unit if none exists
        $conn->query("INSERT INTO `myunits` (id, uname, isdeleted) VALUES (1, 'ق', 0)");
        $defaultUnitId = 1;
    }

    // Determine current starting code
    $rowlstitm = $conn->query('SELECT MAX(code) AS max_code FROM `myitems`')->fetch_assoc();
    $startCode = isset($rowlstitm['max_code']) ? (int)$rowlstitm['max_code'] : 0;

    // Determine starting barcode (Egypt EAN prefix 622)
    $rowlstbarcode = $conn->query('SELECT MAX(CAST(barcode AS UNSIGNED)) AS max_barcode FROM `myitems` WHERE barcode REGEXP \'^[0-9]+$\'')->fetch_assoc();
    $startBarcode = isset($rowlstbarcode['max_barcode']) ? (float)$rowlstbarcode['max_barcode'] : 622000000000;
    if ($startBarcode < 622000000000) {
        $startBarcode = 622000000000;
    }

    // Fetch existing item names in database to prevent duplicates
    $existingNames = [];
    $resNames = $conn->query("SELECT iname FROM `myitems`");
    if ($resNames) {
        while ($rn = $resNames->fetch_assoc()) {
            $existingNames[$rn['iname']] = true;
        }
    }

    // Let's generate items for this chunk
    $conn->begin_transaction();
    
    $generatedCount = 0;
    $itemsInThisChunk = [];

    // Calculate how many items are left for this chunk
    $offset = $chunk * $chunkSize;
    $remaining = $count - $offset;
    $currentChunkLimit = min($chunkSize, $remaining);

    for ($i = 0; $i < $currentChunkLimit; $i++) {
        // Pick a random category
        $catIdx = mt_rand(0, count($categories) - 1);
        $category = $categories[$catIdx];
        
        $groupName = $category['name'];
        $groupId = $groupMap[$groupName] ?? 1;

        // Combine to form a realistic unique product name
        $nameFound = false;
        $iname = '';
        $attempts = 0;
        
        while (!$nameFound && $attempts < 50) {
            $base = $category['bases'][mt_rand(0, count($category['bases']) - 1)];
            $brand = $category['brands'][mt_rand(0, count($category['brands']) - 1)];
            $size = $category['sizes'][mt_rand(0, count($category['sizes']) - 1)];
            
            $iname = $base . ' ' . $brand . ' ' . $size;
            $attempts++;
            
            if (!isset($existingNames[$iname])) {
                $nameFound = true;
            }
        }
        
        // If still duplicate, append random tag
        if (isset($existingNames[$iname])) {
            $iname .= ' #' . mt_rand(1, 9999);
        }
        
        $existingNames[$iname] = true;

        // Generate realistic pricing
        // cost_price: 5.0 to 150.0 EGP
        $cost_price = mt_rand(50, 1500) / 10; 
        
        // price1 (Retail): 15% - 25% markup
        $price1 = round($cost_price * (1 + (mt_rand(15, 25) / 100)), 2);
        
        // price2 (Wholesale): 5% - 12% markup
        $price2 = round($cost_price * (1 + (mt_rand(5, 12) / 100)), 2);
        
        // market_price (Sprice): 2% - 5% markup over price1
        $market_price = round($price1 * (1 + (mt_rand(2, 5) / 100)), 2);

        // Assign consecutive code and barcode
        $itemCode = $startCode + $offset + $i + 1;
        $itemBarcode = (string)($startBarcode + $offset + $i + 1);

        // Insert into myitems
        $stmtItem = $conn->prepare("INSERT INTO `myitems` (iname, name2, code, barcode, info, market_price, cost_price, price1, price2, group1, group2, isdeleted, user) 
                                    VALUES (?, '', ?, ?, 'صنف تجريبي مولد آلياً', ?, ?, ?, ?, ?, 0, 0, ?)");
        
        if ($stmtItem) {
            $stmtItem->bind_param("sisddddii", $iname, $itemCode, $itemBarcode, $market_price, $cost_price, $price1, $price2, $groupId, $userId);
            $stmtItem->execute();
            $last_id = $conn->insert_id;
            $stmtItem->close();

            // Insert default unit mapping in item_units (unit_id = $defaultUnitId, u_val = 1.000)
            $stmtUnit = $conn->prepare("INSERT INTO `item_units` (item_id, unit_id, u_val, unit_barcode, cost_price, price1, price2, price3, isdeleted) 
                                        VALUES (?, ?, 1.000, ?, ?, ?, ?, ?, 0)");
            if ($stmtUnit) {
                $stmtUnit->bind_param("iisdddd", $last_id, $defaultUnitId, $itemBarcode, $cost_price, $price1, $price2, $market_price);
                $stmtUnit->execute();
                $stmtUnit->close();
            }

            $generatedCount++;
            $itemsInThisChunk[] = [
                'code' => $itemCode,
                'barcode' => $itemBarcode,
                'name' => $iname,
                'price' => $price1,
                'group' => $groupName
            ];
        }
    }

    $conn->query("INSERT INTO `process` (type) VALUES ('add item factory batch')");
    $conn->commit();

    echo json_encode([
        'success' => true,
        'generated' => $generatedCount,
        'chunk' => $chunk,
        'items' => $itemsInThisChunk,
        'is_finished' => ($offset + $generatedCount) >= $count
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
    }
    echo json_encode([
        'success' => false,
        'message' => 'حدث خطأ: ' . $e->getMessage()
    ]);
}
