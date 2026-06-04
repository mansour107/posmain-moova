<?php

final class ItemEditorFlash
{
    private const SESSION_KEY = 'item_editor_flash';

    public static function set(string $type, string $code): void
    {
        $_SESSION[self::SESSION_KEY] = [
            'type' => $type === 'success' ? 'success' : 'danger',
            'code' => trim($code),
        ];
    }

    public static function take(): ?array
    {
        $flash = $_SESSION[self::SESSION_KEY] ?? null;
        unset($_SESSION[self::SESSION_KEY]);

        if (!is_array($flash)) {
            return null;
        }

        $type = ($flash['type'] ?? '') === 'success' ? 'success' : 'danger';
        $code = trim((string) ($flash['code'] ?? ''));

        return [
            'type' => $type,
            'message' => self::messageForCode($code, $type),
        ];
    }

    public static function messageForCode(string $code, string $type = 'danger'): string
    {
        if ($code === 'saved' || ($type === 'success' && $code === '')) {
            return 'تم الحفظ بنجاح.';
        }

        $messages = [
            'duplicate_name' => 'يوجد صنف بنفس الاسم (قد يكون صنفاً آخر أو تنوعاً قديماً بنفس الاسم المولّد). اختر اسماً أو نوعاً مختلفاً.',
            'duplicate_barcode' => 'الباركود مستخدم لصنف آخر. غيّر باركود التنوع أو الصنف.',
            'save_failed' => 'تعذّر حفظ البيانات. حاول مرة أخرى.',
            'invalid_image' => 'صيغة الصورة غير مسموحة. استخدم jpg أو png أو gif أو jpeg أو webp.',
        ];

        return $messages[$code] ?? 'حدث خطأ أثناء الحفظ.';
    }
}
