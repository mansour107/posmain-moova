<?php

if (!function_exists('posmain_send_no_store_headers')) {
    function posmain_send_no_store_headers(): void
    {
        if (headers_sent()) {
            return;
        }

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}
