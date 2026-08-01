<?php

/**
 * Converts internal printing diagnostics into short operator-facing Arabic.
 *
 * Internal codes remain useful in logs and durable job records, but must never
 * be rendered directly to restaurant staff.
 */
class PrintUserMessageService
{
    private const MESSAGES = [
        'METHOD_NOT_ALLOWED' => 'هذه العملية غير متاحة من هذه الشاشة.',
        'PERMISSION_DENIED' => 'ليس لديك صلاحية لتنفيذ هذه العملية. اطلب المساعدة من المدير.',
        'SILENT_PRINT_DISABLED' => 'الطباعة المباشرة غير مفعلة حالياً. استخدم طباعة المتصفح أو تواصل مع المسؤول.',
        'PRINT_REQUEST_INVALID' => 'تعذر قراءة طلب الطباعة. حدّث الصفحة ثم حاول مرة أخرى.',
        'PRINT_ACTION_INVALID' => 'العملية المطلوبة غير معروفة. حدّث الصفحة ثم حاول مرة أخرى.',
        'PRINT_ACTION_FAILED' => 'تعذر تنفيذ عملية الطباعة. حاول مرة أخرى، وإذا استمرت المشكلة تواصل مع المسؤول.',
        'PRINT_DISPATCH_FAILED' => 'تعذر إرسال الطباعة. تحقق من حالة الطابعة ثم حاول مرة أخرى.',
        'PRINT_RESPONSE_INVALID' => 'لم يصل رد واضح من خدمة الطباعة. حاول مرة أخرى، وإذا استمرت المشكلة تواصل مع المسؤول.',
        'PRINT_CONNECTION_INVALID' => 'اختر طريقة اتصال صحيحة للطابعة.',
        'PRINT_ROUTE_FUNCTION_REQUIRED' => 'اختر استخداماً واحداً على الأقل لهذه الطابعة، مثل الإيصالات أو طلبات المطبخ.',
        'PRINT_ROUTE_CATEGORY_REQUIRED' => 'اختر تصنيفات المطبخ التي ستُرسل إلى هذه الطابعة، أو اختر كل التصنيفات.',
        'PRINT_ROUTE_FUNCTION_INVALID' => 'نوع المستند المحدد غير مدعوم لهذه الطابعة.',
        'PRINT_ROUTE_NOT_CONFIGURED' => 'لا توجد طابعة مفعلة لهذا النوع من المستندات. راجع مسارات الطابعات.',
        'PRINT_KOT_LINES_REQUIRED' => 'طلب المطبخ لا يحتوي على أصناف قابلة للطباعة.',
        'PRINT_KOT_LINE_INVALID' => 'يوجد صنف غير صالح في طلب المطبخ. أعد فتح الطلب ثم حاول مرة أخرى.',
        'PRINT_KOT_LINE_UNROUTED' => 'يوجد صنف مطبخ غير مربوط بأي طابعة. راجع تصنيفات ومسارات الطابعات.',
        'PRINT_NETWORK_HOST_INVALID' => 'أدخل عنوان الشبكة الصحيح للطابعة، مثل 192.168.1.50.',
        'PRINT_NETWORK_PORT_INVALID' => 'أدخل منفذ اتصال صحيحاً للطابعة. المنفذ المعتاد هو 9100.',
        'PRINT_NETWORK_CONFIG_INVALID' => 'بيانات اتصال الطابعة غير مكتملة. راجع العنوان والمنفذ.',
        'PRINT_NETWORK_CONNECT_FAILED' => 'تعذر الاتصال بالطابعة. تأكد أنها تعمل ومتصلة بالشبكة ثم حاول مرة أخرى.',
        'PRINT_NETWORK_DELIVERY_UNCERTAIN' => 'قد تكون الطباعة وصلت جزئياً. افحص الورق قبل اختيار إعادة المحاولة.',
        'PRINT_CABLE_QUEUE_REQUIRED' => 'اختر الطابعة المثبتة على هذا الجهاز.',
        'PRINT_CABLE_QUEUE_INVALID' => 'اسم الطابعة المثبتة غير صالح. أعد اختيارها من القائمة.',
        'PRINT_BRIDGE_NOT_CONFIGURED' => 'خدمة الطباعة المباشرة غير مجهزة على هذا الجهاز. شغّل إعداد الطباعة المحلي.',
        'PRINT_BRIDGE_UNAVAILABLE' => 'خدمة الطباعة المحلية لا تعمل. شغّلها ثم حاول مرة أخرى.',
        'PRINT_BRIDGE_AUTH_FAILED' => 'تعذر التحقق من خدمة الطباعة المحلية. أعد تشغيل إعداد الطباعة أو تواصل مع المسؤول.',
        'PRINT_BRIDGE_RESPONSE_INVALID' => 'وصل رد غير واضح من خدمة الطباعة المحلية. أعد تشغيل الخدمة ثم حاول مرة أخرى.',
        'PRINT_BRIDGE_DELIVERY_UNCERTAIN' => 'قد تكون الطباعة وصلت إلى الطابعة. افحص الورق قبل إعادة المحاولة.',
        'PRINT_BRIDGE_QUEUE_NOT_FOUND' => 'الطابعة المتصلة بالكابل غير موجودة على هذا الجهاز. تأكد من توصيلها وتثبيتها.',
        'PRINT_BRIDGE_QUEUE_OFFLINE' => 'الطابعة المتصلة بالكابل غير جاهزة. تأكد من تشغيلها وتوصيل الكابل.',
        'PRINT_BRIDGE_SUBMIT_FAILED' => 'لم يتمكن الجهاز من إرسال المستند إلى الطابعة. تحقق من الطابعة ثم حاول مرة أخرى.',
        'PRINT_BRIDGE_IDEMPOTENCY_CONFLICT' => 'تعارض طلب الطباعة مع محاولة سابقة. لا تعِد الطباعة قبل مراجعة سجل المهام.',
        'PRINT_BRIDGE_PAYLOAD_INVALID' => 'تعذر تجهيز محتوى الطباعة. أعد فتح المستند ثم حاول مرة أخرى.',
        'PRINT_BRIDGE_SECRET_REQUIRED' => 'إعداد خدمة الطباعة المحلية غير مكتمل. شغّل أداة التثبيت مرة أخرى.',
        'PRINT_BRIDGE_ROUTE_NOT_FOUND' => 'إصدار خدمة الطباعة المحلية غير متوافق. حدّث التطبيق ثم أعد تشغيل الخدمة.',
        'PRINT_BRIDGE_REQUEST_INVALID' => 'تعذر إرسال الطلب إلى خدمة الطباعة المحلية. أعد تشغيل الخدمة ثم حاول مرة أخرى.',
        'PRINT_RELIABLE_SCHEMA_REQUIRED' => 'إعداد الطباعة غير مكتمل. يرجى إكمال تحديث النظام ثم المحاولة مرة أخرى.',
        'PRINT_JOB_PRINTER_REQUIRED' => 'مهمة الطباعة غير مرتبطة بطابعة. راجع إعدادات المسار.',
        'PRINT_JOB_ALREADY_CLAIMED' => 'تتم معالجة مهمة الطباعة الآن على جهاز آخر. انتظر قليلاً ثم حدّث السجل.',
        'PRINT_JOB_NOT_QUEUED' => 'مهمة الطباعة لم تعد في قائمة الانتظار. حدّث السجل لمعرفة حالتها الحالية.',
        'PRINT_PRINTER_REQUIRED' => 'اختر طابعة صحيحة ثم حاول مرة أخرى.',
        'PRINT_REQUEST_KEY_INVALID' => 'تعذر تأمين محاولة الطباعة. حدّث الصفحة ثم حاول مرة أخرى.',
        'PRINT_UNCERTAIN_RETRY_CONFIRMATION_REQUIRED' => 'افحص الورق أولاً، ثم أكد أن المستند لم يُطبع قبل إعادة المحاولة.',
        'PRINT_ORDER_JOB_TYPE_INVALID' => 'نوع طباعة الطلب غير مدعوم.',
        'PRINT_DOCUMENT_JOB_TYPE_INVALID' => 'نوع المستند غير مدعوم للطباعة.',
        'PRINT_DOCUMENT_TITLE_REQUIRED' => 'اكتب عنواناً واضحاً للمستند قبل الطباعة.',
        'PRINT_DOCUMENT_CONTENT_REQUIRED' => 'لا يوجد محتوى يمكن طباعته.',
        'ORDER_ID_REQUIRED' => 'تعذر تحديد الطلب المطلوب طباعته. أعد فتح الطلب ثم حاول مرة أخرى.',
        'PRINTER_NOT_FOUND' => 'الطابعة غير موجودة أو لا تتبع هذا الفرع.',
        'PRINTER_NAME_REQUIRED' => 'اكتب اسماً واضحاً للطابعة.',
        'PRINTER_CONNECTION_INVALID' => 'طريقة اتصال الطابعة غير مدعومة.',
        'SILENT_PRINT_BROWSER_PRINTER_UNSUPPORTED' => 'هذه الطابعة تعمل بطباعة المتصفح فقط ولا تدعم الطباعة المباشرة.',
        'SILENT_PRINT_TRANSPORT_UNSUPPORTED' => 'طريقة اتصال هذه الطابعة لا تدعم الطباعة المباشرة.',
    ];

    public static function forException(Throwable $exception): string
    {
        return self::forCode((string) $exception->getMessage());
    }

    public static function forCode(string $diagnostic): string
    {
        $code = self::code($diagnostic);
        return self::MESSAGES[$code]
            ?? 'حدث خطأ غير متوقع أثناء الطباعة. حاول مرة أخرى، وإذا استمرت المشكلة تواصل مع المسؤول.';
    }

    public static function code(string $diagnostic): string
    {
        $code = strtoupper(trim(strtok($diagnostic, ':') ?: ''));
        return preg_match('/^[A-Z][A-Z0-9_]{2,120}$/', $code) === 1
            ? $code
            : 'PRINT_ACTION_FAILED';
    }

    public static function log(Throwable $exception, $context = []): void
    {
        $record = [
            'area' => 'printing',
            'code' => self::code((string) $exception->getMessage()),
            'exception' => get_class($exception),
            'context' => $context,
        ];
        error_log('POSMAIN_PRINT_ERROR ' . json_encode(
            $record,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }
}
