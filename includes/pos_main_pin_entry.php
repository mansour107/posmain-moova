<?php

/**
 * Shared local-PIN POS entry: skip second unlock and run ShiftEntryService.
 *
 * @return array{unlocked: bool, state: string, message: string, blocked: bool}
 */
function posmain_apply_main_pin_pos_entry(mysqli $conn, string $fallbackUnlockPage = 'pos_barcode.php'): array
{
    require_once dirname(__DIR__) . '/config/app_config.php';
    require_once __DIR__ . '/auth_guard.php';

    $result = [
        'unlocked' => auth_guard_is_pos_barcode_unlocked(),
        'state' => (string) ($_SESSION['posmain_shift_entry_state'] ?? ''),
        'message' => (string) ($_SESSION['posmain_shift_entry_message'] ?? ''),
        'blocked' => false,
    ];

    if (!function_exists('posmain_is_pin_main_auth') || !posmain_is_pin_main_auth()) {
        $state = $result['state'];
        if ($result['unlocked'] && $state !== '' && $state !== 'selling_ready') {
            $result['blocked'] = $state !== 'open_count_pending';
            $currentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? $fallbackUnlockPage));
            if ($currentScript !== 'pos_barcode.php') {
                $queryState = match ($state) {
                    'stale_shift' => 'stale',
                    'register_transfer_required' => 'transfer',
                    'branch_blocked' => 'blocked',
                    'open_count_pending' => 'open_count',
                    default => 'recovery',
                };
                header('Location: pos_barcode.php?shift=' . $queryState);
                exit;
            }
        }
        return $result;
    }
    if (!empty($_SESSION['posmain_pin_must_change']) || !empty($_SESSION['posmain_bootstrap_pending'])) {
        return $result;
    }
    require_once dirname(__DIR__) . '/classes/Pos/Service/ShiftEntryService.php';
    $entryUserId = (int) ($_SESSION['userid'] ?? 0);
    if ($entryUserId < 1) {
        return $result;
    }

    try {
        $entry = (new ShiftEntryService())->resolveForUser($conn, $entryUserId);
        $state = (string) ($entry['state'] ?? '');
        if ($state === ShiftEntryService::STATE_REGISTER_UNPAIRED) {
            header('Location: register_pair.php');
            exit;
        }
        if ($state === ShiftEntryService::STATE_PERMISSION_DENIED) {
            header('Location: no_access.php');
            exit;
        }

        pos_set_acting_user($entryUserId);
        $_SESSION['pos_authenticated'] = true;
        $_SESSION['pos_user_id'] = $entryUserId;
        if (!empty($entry['drawer_session']['id'])) {
            $_SESSION['pos_drawer_session_id'] = (int) $entry['drawer_session']['id'];
        }
        $_SESSION['posmain_shift_entry_state'] = $state;
        $_SESSION['posmain_shift_entry_message'] = (string) ($entry['message'] ?? '');

        $result['unlocked'] = true;
        $result['state'] = $state;
        $result['message'] = (string) ($entry['message'] ?? '');
        $result['blocked'] = in_array($state, [
            ShiftEntryService::STATE_BRANCH_BLOCKED,
            ShiftEntryService::STATE_REGISTER_TRANSFER_REQUIRED,
            ShiftEntryService::STATE_STALE_SHIFT,
            ShiftEntryService::STATE_PERMISSION_DENIED,
            'entry_error',
        ], true);

        $currentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? $fallbackUnlockPage));
        if ($state !== ShiftEntryService::STATE_SELLING_READY && $currentScript !== 'pos_barcode.php') {
            $redirect = trim((string) ($entry['redirect'] ?? ''));
            header('Location: ' . ($redirect !== '' ? $redirect : 'pos_barcode.php?shift=recovery'));
            exit;
        }
    } catch (Throwable $exception) {
        error_log('posmain_apply_main_pin_pos_entry failed on ' . $fallbackUnlockPage . ': ' . $exception->getMessage());
        pos_set_acting_user($entryUserId);
        $_SESSION['pos_authenticated'] = true;
        $_SESSION['pos_user_id'] = $entryUserId;
        $_SESSION['posmain_shift_entry_state'] = 'entry_error';
        $_SESSION['posmain_shift_entry_message'] = 'تعذر التحقق من حالة الوردية. لا يمكن بدء البيع حتى إعادة المحاولة.';
        $result['unlocked'] = true;
        $result['state'] = 'entry_error';
        $result['message'] = (string) $_SESSION['posmain_shift_entry_message'];
        $result['blocked'] = true;
        $currentScript = basename((string) ($_SERVER['SCRIPT_NAME'] ?? $fallbackUnlockPage));
        if ($currentScript !== 'pos_barcode.php') {
            header('Location: pos_barcode.php?shift=error');
            exit;
        }
    }

    return $result;
}
