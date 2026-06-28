<?php

class PosCustomerPhoneService
{
    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';
        if ($digits === '') {
            return '';
        }

        if (strlen($digits) === 10 && $digits[0] === '1') {
            $digits = '20' . $digits;
        } elseif (strlen($digits) === 11 && $digits[0] === '0') {
            $digits = '20' . substr($digits, 1);
        }

        return $digits;
    }

    public function displayPhone(string $phone): string
    {
        $normalized = $this->normalizePhone($phone);
        if ($normalized === '') {
            return trim($phone);
        }

        if (strlen($normalized) === 12 && strpos($normalized, '20') === 0) {
            return '0' . substr($normalized, 2);
        }

        return $normalized;
    }

    public function isValidPhone(string $phone): bool
    {
        $normalized = $this->normalizePhone($phone);
        if ($normalized === '') {
            return false;
        }

        return strlen($normalized) >= 10 && strlen($normalized) <= 15;
    }

    public function maskPhone(string $phone): string
    {
        $display = $this->displayPhone($phone);
        if (strlen($display) <= 4) {
            return $display;
        }

        return str_repeat('*', max(0, strlen($display) - 4)) . substr($display, -4);
    }
}
