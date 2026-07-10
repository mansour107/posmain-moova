<?php
/**
 * Shared 4-digit PIN pad fragment (RTL, accessible).
 *
 * Expected vars:
 * - $pinPadId (string) unique prefix
 * - $pinPadCsrf (string) csrf token value
 * - $pinPadEndpoint (string) POST URL
 * - $pinPadTitle (string)
 * - $pinPadSubtitle (string)
 * - $pinPadError (string|null)
 * - $pinPadDigits (int) default 4
 * - $pinPadExtraFields (string) optional HTML hidden fields
 * - $pinPadMode (string) login|change_current|change_new
 */
$pinPadId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($pinPadId ?? 'pinPad')) ?: 'pinPad';
$pinPadDigits = max(4, min(4, (int) ($pinPadDigits ?? 4)));
$pinPadTitle = (string) ($pinPadTitle ?? 'أدخل الرمز');
$pinPadSubtitle = (string) ($pinPadSubtitle ?? '');
$pinPadError = trim((string) ($pinPadError ?? ''));
$pinPadCsrf = (string) ($pinPadCsrf ?? '');
$pinPadEndpoint = (string) ($pinPadEndpoint ?? '');
$pinPadExtraFields = (string) ($pinPadExtraFields ?? '');
$pinPadMode = (string) ($pinPadMode ?? 'login');
?>
<div class="ppm-shell" id="<?= htmlspecialchars($pinPadId, ENT_QUOTES, 'UTF-8') ?>"
     data-endpoint="<?= htmlspecialchars($pinPadEndpoint, ENT_QUOTES, 'UTF-8') ?>"
     data-digits="<?= (int) $pinPadDigits ?>"
     data-mode="<?= htmlspecialchars($pinPadMode, ENT_QUOTES, 'UTF-8') ?>"
     role="group"
     aria-label="<?= htmlspecialchars($pinPadTitle, ENT_QUOTES, 'UTF-8') ?>">
    <div class="ppm-card">
        <h1 class="ppm-title"><?= htmlspecialchars($pinPadTitle, ENT_QUOTES, 'UTF-8') ?></h1>
        <?php if ($pinPadSubtitle !== ''): ?>
            <p class="ppm-sub"><?= htmlspecialchars($pinPadSubtitle, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <div class="ppm-error<?= $pinPadError === '' ? ' is-hidden' : '' ?>" id="<?= htmlspecialchars($pinPadId, ENT_QUOTES, 'UTF-8') ?>Error" role="alert" aria-live="polite">
            <?= htmlspecialchars($pinPadError, ENT_QUOTES, 'UTF-8') ?>
        </div>

        <div class="ppm-dots" id="<?= htmlspecialchars($pinPadId, ENT_QUOTES, 'UTF-8') ?>Dots" dir="ltr" aria-hidden="true">
            <?php for ($i = 0; $i < $pinPadDigits; $i++): ?>
                <span class="ppm-dot" data-idx="<?= $i ?>"></span>
            <?php endfor; ?>
        </div>
        <span class="visually-hidden" id="<?= htmlspecialchars($pinPadId, ENT_QUOTES, 'UTF-8') ?>Status" aria-live="polite"></span>

        <div class="ppm-grid" id="<?= htmlspecialchars($pinPadId, ENT_QUOTES, 'UTF-8') ?>Grid">
            <?php
            $keys = ['1','2','3','4','5','6','7','8','9','مسح','0','دخول'];
            foreach ($keys as $key):
                $class = 'ppm-key';
                if ($key === 'مسح') {
                    $class .= ' action';
                } elseif ($key === 'دخول') {
                    $class .= ' enter';
                }
            ?>
                <button type="button" class="<?= $class ?>" data-key="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"
                    <?= $key === 'دخول' ? 'aria-label="تأكيد الرمز"' : ($key === 'مسح' ? 'aria-label="مسح"' : 'aria-label="' . $key . '"') ?>>
                    <?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>
                </button>
            <?php endforeach; ?>
        </div>

        <form id="<?= htmlspecialchars($pinPadId, ENT_QUOTES, 'UTF-8') ?>Form" method="post" action="<?= htmlspecialchars($pinPadEndpoint, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" class="is-hidden">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($pinPadCsrf, ENT_QUOTES, 'UTF-8') ?>">
            <?= $pinPadExtraFields ?>
        </form>
    </div>
</div>
