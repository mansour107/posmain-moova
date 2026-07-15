<?php
/** @var bool $posmainNeedsOpenCount */
/** @var bool $posmainOpenCountDenied */
$showOpenOverlay = !empty($posmainNeedsOpenCount) || !empty($posmainOpenCountDenied);
if (!$showOpenOverlay) {
    return;
}
$openCountDenied = !empty($posmainOpenCountDenied);
?>

<div id="pshOpenOverlay" class="psh-overlay" role="dialog" aria-modal="true" aria-labelledby="pshOpenTitle"
    <?php if ($openCountDenied): ?>data-psh-open-denied="1"<?php endif; ?>>
    <div class="psh-card">
        <div class="psh-card-head">
            <h2 id="pshOpenTitle"><i class="fas fa-cash-register me-2"></i>افتتاح الدرج</h2>
            <p id="pshOpenAttemptLabel"><?= $openCountDenied
                ? 'هذه الشاشة للكاشير أو المدير فقط'
                : 'عدّ النقد في الدرج قبل بدء الشيفت' ?></p>
        </div>
        <div class="psh-card-body">
            <div id="pshOpenPermissionDenied" class="psh-message is-warn <?= $openCountDenied ? '' : 'psh-hidden' ?>"
                data-testid="psh-open-permission-denied" role="alert">
                <p><strong>ليس لديك صلاحية افتتاح الدرج</strong></p>
                <p class="mb-2">افتتاح الشيفت وعدّ الدرج متاح للكاشير أو المدير فقط. يمكنك متابعة طلبات الطاولات بعد أن يفتح الكاشير الشيفت، أو اطلب من المدير المساعدة.</p>
                <div class="psh-actions">
                    <a href="pos_barcode.php?logout=1" class="psh-btn psh-btn-primary" data-psh-open-lock>
                        العودة لقفل نقطة البيع
                    </a>
                    <a href="dashboard.php" class="psh-btn psh-btn-secondary" data-psh-open-dashboard>
                        لوحة التحكم
                    </a>
                </div>
            </div>
            <div id="pshOpenUnassignedNote" class="psh-message is-info psh-hidden">
                يوجد حركات نقدية غير مربوطة — سيتم احتسابها في التوقع
            </div>
            <div id="pshOpenBaselineRequired" class="psh-message is-warn psh-hidden">
                <p><strong>يتطلب تهيئة العهد من المدير</strong></p>
                <p class="mb-0">قبل أول شيفت في هذا الفرع، يجب على المدير تحديد رصيد الافتتاح من صفحة الشيفتات المغلقة.</p>
            </div>
            <div id="pshOpenBranchBlocked" class="psh-message is-warn psh-hidden">
                <p><strong>الدرج مفتوح بالفعل</strong></p>
                <p id="pshOpenBranchBlockedText" class="mb-2"></p>
                <p class="mb-0 text-muted" style="font-size:0.9rem">يمكن للمدير استلام الدرج من هنا بعد عدّ النقد وإدخال رمز PIN.</p>
                <div id="pshOpenTakeoverForm" class="psh-takeover-form">
                    <p id="pshTakeoverAttemptLabel" class="psh-takeover-label psh-hidden"></p>
                    <div id="pshTakeoverAmountWrap">
                        <label class="psh-takeover-label" for="pshTakeoverAmount">المبلغ المعدود في الدرج</label>
                        <input type="text" id="pshTakeoverAmount" class="psh-amount-input psh-amount-input--sm"
                               inputmode="decimal" autocomplete="off" placeholder="0.00">
                    </div>
                    <div id="pshTakeoverVariance" class="psh-variance-card psh-hidden">
                        <p class="psh-variance-label" id="pshTakeoverVarianceLabel"></p>
                        <p class="psh-variance-amount" id="pshTakeoverVarianceAmount"></p>
                        <p class="psh-variance-label">سيتم تسجيل الفرق والمتابعة بفتح ورديتك</p>
                    </div>
                    <div id="pshTakeoverReasonWrap" class="psh-hidden">
                        <label class="psh-takeover-label" for="pshTakeoverReason">سبب الاستلام / الإغلاق</label>
                        <textarea id="pshTakeoverReason" class="psh-takeover-reason" rows="2" placeholder="مثال: الكاشير السابق غادر دون إغلاق"></textarea>
                    </div>
                    <div id="pshOpenTakeoverMessage" class="psh-message psh-hidden"></div>
                    <div class="psh-actions">
                        <button type="button" class="psh-btn psh-btn-primary" data-psh-open-takeover data-phase="count">تأكيد العد</button>
                    </div>
                </div>
            </div>
            <div id="pshOpenCountStep" class="<?= $openCountDenied ? 'psh-hidden' : '' ?>">
                <input type="number" id="pshOpenAmount" class="psh-amount-input" placeholder="0.00" step="0.01" min="0" <?= $openCountDenied ? '' : 'autofocus' ?>>
                <div id="pshOpenMessage" class="psh-message psh-hidden"></div>
                <div class="psh-actions">
                    <button type="button" class="psh-btn psh-btn-primary" data-psh-open-submit>تأكيد العد</button>
                </div>
            </div>
            <div id="pshOpenVariance" class="psh-variance-card psh-hidden">
                <p class="psh-variance-label" id="pshOpenVarianceLabel"></p>
                <p class="psh-variance-amount" id="pshOpenVarianceAmount"></p>
                <p class="psh-variance-label">سيتم فتح الشيفت وتسجيل الحالة للمراجعة</p>
                <div class="psh-actions">
                    <button type="button" class="psh-btn psh-btn-primary psh-hidden" data-psh-open-acknowledge>متابعة</button>
                </div>
            </div>
        </div>
    </div>
</div>
