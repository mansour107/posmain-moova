<?php

include('../includes/connect.php');
require_once __DIR__ . '/../includes/pos_operational_store.php';

if (posmain_single_store_mode_enabled()) {
    die('وضع المخزن الواحد مفعّل: لا يمكن إنشاء مخازن إضافية من هنا.');
}

header('Location: ../add_store.php');
exit;
