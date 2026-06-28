<?php

if (!function_exists('posmain_resolve_pos_user_id')) {
    function posmain_resolve_pos_user_id(array $request = []): int
    {
        $userId = 0;
        if (function_exists('current_user_id')) {
            $userId = (int) current_user_id();
        }
        if ($userId < 1) {
            $userId = (int) ($request['user_id'] ?? $_SESSION['userid'] ?? $_SESSION['user_id'] ?? 0);
        }

        return $userId > 0 ? $userId : 0;
    }
}
