/**
 * POS delivery customer capture, validation, and cart fee integration.
 */
(function (window, $) {
    'use strict';

    if (!$) {
        return;
    }

    window.posDeliveryState = window.posDeliveryState || {
        confirmed: false,
        clientId: null,
        phone: '',
        name: '',
        address: '',
        zoneId: null,
        zoneName: '',
        fee: 0,
        isExistingClient: false,
    };

    let searchTimeout = null;
    let lastSearchedPhone = '';
    let modalDismissConfirmed = false;

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function isDeliveryMode() {
        return $('input[name="age"]:checked').val() === '3';
    }

    function getFieldValues() {
        return {
            phone: $('#customer_phone').val().trim(),
            name: ($('#customer_name').val() || '').trim(),
            address: ($('#customer_address').val() || '').trim(),
        };
    }

    function isCustomerFormComplete() {
        const values = getFieldValues();
        return values.phone.length >= 10 && values.name !== '' && values.address !== '';
    }

    function updateConfirmButtonVisibility() {
        if (isCustomerFormComplete()) {
            $('#confirmOrderBtn').show();
        } else {
            $('#confirmOrderBtn').hide();
        }
    }

    function removeDeliveryHiddenFields() {
        $('#posForm input[name="delivery_customer_name"], #posForm input[name="delivery_customer_phone"], #posForm input[name="delivery_customer_address"], #posForm input[name="delivery_client_id"], #posForm input[name="delivery_zone_id"], #posForm input[name="delivery_zone_name"], #posForm input[name="delivery_fee"]').remove();
    }

    function syncHiddenFieldsToForm() {
        removeDeliveryHiddenFields();
        if (!window.posDeliveryState.confirmed) {
            return;
        }
        const state = window.posDeliveryState;
        const fields = {
            delivery_customer_name: state.name,
            delivery_customer_phone: state.phone,
            delivery_customer_address: state.address,
            delivery_client_id: state.clientId || '',
            delivery_zone_id: state.zoneId || '',
            delivery_zone_name: state.zoneName || '',
            delivery_fee: state.fee || 0,
        };
        Object.keys(fields).forEach(function (name) {
            $('<input>').attr({ type: 'hidden', name: name, value: fields[name] }).appendTo('#posForm');
        });
    }

    function setDeliveryPayButtonsEnabled(enabled) {
        const $savePay = $('#posForm button[type="submit"], #posForm input[type="submit"], .pos-save-btn, .pos-pay-btn, button[onclick*="submitPOS"]');
        $savePay.prop('disabled', !enabled);
        if (!enabled) {
            $savePay.attr('title', 'أكمل بيانات عميل الدليفري أولاً');
        } else {
            $savePay.removeAttr('title');
        }
    }

    function renderDeliveryBar() {
        const $bar = $('#posDeliveryBar');
        if (!$bar.length) {
            return;
        }

        if (!isDeliveryMode()) {
            $bar.addClass('d-none').empty();
            setDeliveryPayButtonsEnabled(true);
            return;
        }

        $bar.removeClass('d-none');
        if (window.posDeliveryState.confirmed) {
            const state = window.posDeliveryState;
            const phoneShort = state.phone.length > 4
                ? state.phone.slice(0, 4) + '…' + state.phone.slice(-2)
                : state.phone;
            const zoneLabel = state.zoneName ? ' — ' + escapeHtml(state.zoneName) : '';
            const feeLabel = state.fee > 0 ? ' — رسوم: ' + Number(state.fee).toFixed(2) : '';
            $bar.html(
                '<div class="pos-delivery-bar pos-delivery-bar--confirmed">' +
                '<span class="pos-delivery-bar__badge"><i class="fas fa-motorcycle me-1"></i>دليفري</span>' +
                '<span class="pos-delivery-bar__summary">' +
                escapeHtml(state.name) + ' — ' + escapeHtml(phoneShort) + ' — ' + escapeHtml(state.address) +
                zoneLabel + feeLabel +
                '</span>' +
                '<span class="pos-delivery-bar__actions">' +
                '<button type="button" class="btn btn-sm btn-outline-primary" id="posDeliveryEditBtn"><i class="fas fa-edit"></i> تعديل</button>' +
                '<button type="button" class="btn btn-sm btn-outline-secondary" id="posDeliveryClearBtn"><i class="fas fa-times"></i></button>' +
                '</span></div>'
            );
            setDeliveryPayButtonsEnabled(true);
        } else {
            $bar.html(
                '<div class="pos-delivery-bar pos-delivery-bar--pending">' +
                '<span class="pos-delivery-bar__badge"><i class="fas fa-motorcycle me-1"></i>دليفري</span>' +
                '<span class="pos-delivery-bar__summary text-warning">لم يتم تحديد عميل الدليفري</span>' +
                '<button type="button" class="btn btn-sm btn-warning" id="posDeliveryAddBtn"><i class="fas fa-user-plus me-1"></i>إضافة عميل</button>' +
                '</div>'
            );
            setDeliveryPayButtonsEnabled(false);
        }
    }

    function renderDeliveryFeeRow() {
        $('#posDeliveryFeeRow').remove();
        if (!isDeliveryMode() || !window.posDeliveryState.confirmed || !(window.posDeliveryState.fee > 0)) {
            if (typeof window.recalculateOrderTotals === 'function') {
                window.recalculateOrderTotals();
            }
            return;
        }
        const fee = Number(window.posDeliveryState.fee).toFixed(2);
        const row = $(
            '<div id="posDeliveryFeeRow" class="item-card-order pos-delivery-fee-row border-top pt-2 mt-2" data-delivery-fee="1">' +
            '<div class="d-flex justify-content-between align-items-center">' +
            '<span><i class="fas fa-shipping-fast me-1 text-info"></i>رسوم التوصيل</span>' +
            '<strong>' + fee + ' ج.م</strong>' +
            '</div></div>'
        );
        $('#itemData').append(row);
        if (typeof window.recalculateOrderTotals === 'function') {
            window.recalculateOrderTotals();
        }
    }

    function applyConfirmedCustomer(data) {
        window.posDeliveryState = {
            confirmed: true,
            clientId: data.client_id || data.id || null,
            phone: data.phone,
            name: data.name,
            address: data.address,
            zoneId: $('#delivery_zone_id').val() || window.posDeliveryState.zoneId || null,
            zoneName: $('#delivery_zone_id option:selected').text() || window.posDeliveryState.zoneName || '',
            fee: parseFloat($('#delivery_zone_id option:selected').data('fee') || window.posDeliveryState.fee || 0) || 0,
            isExistingClient: !!data.isExistingClient,
        };
        syncHiddenFieldsToForm();
        renderDeliveryBar();
        renderDeliveryFeeRow();
    }

    function clearDeliverySession(revertMode) {
        window.posDeliveryState = {
            confirmed: false,
            clientId: null,
            phone: '',
            name: '',
            address: '',
            zoneId: null,
            zoneName: '',
            fee: 0,
            isExistingClient: false,
        };
        removeDeliveryHiddenFields();
        clearDeliveryFormFields();
        renderDeliveryBar();
        renderDeliveryFeeRow();
        if (revertMode) {
            $('#age1').prop('checked', true).trigger('change');
        }
    }

    function clearDeliveryFormFields() {
        $('#customer_phone').val('').removeClass('border-success border-info border-danger border-warning')
            .attr('placeholder', 'أدخل رقم العميل (البحث يبدأ بعد 3 أرقام)');
        $('#customer_result').html('');
        $('#saveCustomerBtn').html('<i class="fas fa-save me-1"></i>حفظ').show();
        $('#confirmOrderBtn').hide();
        lastSearchedPhone = '';
        if (searchTimeout) {
            clearTimeout(searchTimeout);
            searchTimeout = null;
        }
    }

    function loadDeliveryZones() {
        const $select = $('#delivery_zone_id');
        if (!$select.length) {
            return;
        }
        $.getJSON('ajax/delivery_zones_list.php')
            .done(function (response) {
                const zones = (response && response.zones) || [];
                let html = '<option value="">— اختر منطقة التوصيل —</option>';
                zones.forEach(function (zone) {
                    html += '<option value="' + zone.id + '" data-fee="' + zone.fee + '">' +
                        escapeHtml(zone.name) + ' (' + Number(zone.fee).toFixed(2) + ' ج.م)</option>';
                });
                $select.html(html);
            })
            .fail(function () {
                $select.html('<option value="">— لا توجد مناطق —</option>');
            });
    }

    function showNewCustomerForm() {
        const currentPhone = $('#customer_phone').val().trim();
        $('#customer_result').html(
            '<div class="alert alert-info mb-3"><i class="fas fa-user-plus me-2"></i>عميل جديد - يرجى إدخال بياناته</div>' +
            '<div class="mb-3"><label class="form-label fw-bold">رقم الموبايل</label>' +
            '<input type="text" class="form-control" id="customer_phone_display" value="' + escapeHtml(currentPhone) + '" readonly></div>' +
            '<div class="mb-3"><label class="form-label fw-bold">اسم العميل</label>' +
            '<input type="text" class="form-control" id="customer_name" placeholder="اسم العميل"></div>' +
            '<div class="mb-3"><label class="form-label fw-bold">العنوان</label>' +
            '<textarea class="form-control" id="customer_address" rows="2" placeholder="عنوان العميل"></textarea></div>' +
            '<div class="mb-3"><label class="form-label fw-bold">منطقة التوصيل</label>' +
            '<select class="form-select" id="delivery_zone_id"><option value="">جاري التحميل...</option></select></div>'
        );
        $('#saveCustomerBtn').html('<i class="fas fa-save me-1"></i>حفظ');
        loadDeliveryZones();
        updateConfirmButtonVisibility();
    }

    function searchCustomerDynamic(phone) {
        $('#customer_phone').addClass('border-warning').attr('placeholder', 'جاري البحث...');
        $('#customer_result').html(
            '<div class="text-center py-2"><div class="spinner-border spinner-border-sm text-primary"></div>' +
            '<small class="d-block mt-1 text-muted">جاري البحث عن العميل...</small></div>'
        );

        $.ajax({
            url: 'do/search_customer.php',
            method: 'POST',
            dataType: 'json',
            data: { phone: phone },
        }).done(function (response) {
            $('#customer_phone').removeClass('border-warning').attr('placeholder', 'أدخل رقم العميل (البحث يبدأ بعد 3 أرقام)');
            if (response && response.found) {
                $('#customer_phone').addClass('border-success');
                $('#customer_result').html(
                    '<div class="alert alert-success mb-3"><i class="fas fa-check-circle me-2"></i>تم العثور على العميل</div>' +
                    '<div class="mb-3"><label class="form-label fw-bold">رقم الموبايل</label>' +
                    '<input type="text" class="form-control" id="customer_phone_display" value="' + escapeHtml(phone) + '" readonly></div>' +
                    '<div class="mb-3"><label class="form-label fw-bold">اسم العميل</label>' +
                    '<input type="text" class="form-control" id="customer_name" value="' + escapeHtml(response.name) + '"></div>' +
                    '<div class="mb-3"><label class="form-label fw-bold">العنوان</label>' +
                    '<textarea class="form-control" id="customer_address" rows="2">' + escapeHtml(response.address) + '</textarea></div>' +
                    '<div class="mb-3"><label class="form-label fw-bold">منطقة التوصيل</label>' +
                    '<select class="form-select" id="delivery_zone_id"><option value="">جاري التحميل...</option></select></div>'
                );
                $('#saveCustomerBtn').html('<i class="fas fa-save me-1"></i>حفظ التعديل');
                loadDeliveryZones();
                updateConfirmButtonVisibility();
            } else {
                $('#customer_phone').addClass('border-info');
                showNewCustomerForm();
            }
        }).fail(function () {
            $('#customer_phone').removeClass('border-warning').addClass('border-danger');
            showNewCustomerForm();
        });
    }

    function persistCustomerThenConfirm() {
        const values = getFieldValues();
        if (!isCustomerFormComplete()) {
            Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'يرجى ملء جميع الحقول (رقم الموبايل 10 أرقام على الأقل)' });
            return;
        }

        const isUpdate = window.posDeliveryState.isExistingClient || $('#saveCustomerBtn').text().indexOf('تعديل') !== -1;
        const url = isUpdate ? 'do/update_customer.php' : 'do/save_customer.php';

        $.ajax({
            url: url,
            method: 'POST',
            dataType: 'json',
            data: {
                phone: values.phone,
                name: values.name,
                address: values.address,
            },
        }).done(function (response) {
            if (!response || !response.success) {
                Swal.fire({
                    icon: 'error',
                    title: 'خطأ',
                    text: (response && response.error) ? response.error : 'فشل حفظ بيانات العميل',
                });
                return;
            }
            modalDismissConfirmed = true;
            applyConfirmedCustomer({
                client_id: response.client_id,
                phone: values.phone,
                name: values.name,
                address: values.address,
                isExistingClient: true,
            });
            $('#deliveryModal').modal('hide');
            Swal.fire({
                icon: 'success',
                title: 'تم بنجاح',
                text: 'تم تأكيد بيانات عميل الدليفري',
                timer: 1800,
                showConfirmButton: false,
            });
        }).fail(function (xhr) {
            Swal.fire({
                icon: 'error',
                title: 'خطأ في الاتصال',
                text: xhr.responseText ? xhr.responseText.substring(0, 120) : 'تعذر حفظ بيانات العميل',
            });
        });
    }

    window.openDeliveryModal = function (message) {
        if (message) {
            $('#deliveryModalHint').text(message).removeClass('d-none');
        } else {
            $('#deliveryModalHint').addClass('d-none').text('');
        }
        if (window.posDeliveryState.confirmed) {
            $('#customer_phone').val(window.posDeliveryState.phone);
            $('#customer_result').html(
                '<div class="mb-3"><label class="form-label fw-bold">اسم العميل</label>' +
                '<input type="text" class="form-control" id="customer_name" value="' + escapeHtml(window.posDeliveryState.name) + '"></div>' +
                '<div class="mb-3"><label class="form-label fw-bold">العنوان</label>' +
                '<textarea class="form-control" id="customer_address" rows="2">' + escapeHtml(window.posDeliveryState.address) + '</textarea></div>' +
                '<div class="mb-3"><label class="form-label fw-bold">منطقة التوصيل</label>' +
                '<select class="form-select" id="delivery_zone_id"><option value="">جاري التحميل...</option></select></div>'
            );
            loadDeliveryZones();
            updateConfirmButtonVisibility();
        }
        $('#deliveryModal').modal('show');
    };

    window.clearDeliveryForm = clearDeliveryFormFields;

    window.confirmDeliveryOrder = persistCustomerThenConfirm;

    window.saveCustomerData = persistCustomerThenConfirm;

    window.posDeliveryIsReadyForSubmit = function () {
        if (!isDeliveryMode()) {
            return true;
        }
        return !!window.posDeliveryState.confirmed;
    };

    window.posDeliveryGetFee = function () {
        return isDeliveryMode() && window.posDeliveryState.confirmed
            ? (parseFloat(window.posDeliveryState.fee) || 0)
            : 0;
    };

    window.posDeliveryOnModeChange = function (modeValue) {
        if (modeValue === '3') {
            renderDeliveryBar();
            if (!window.posDeliveryState.confirmed) {
                window.openDeliveryModal();
            }
        } else {
            $('#posDeliveryBar').addClass('d-none');
            setDeliveryPayButtonsEnabled(true);
            renderDeliveryFeeRow();
        }
    };

    $(document).ready(function () {
        $(document).on('input', '#customer_name, #customer_address, #customer_phone', updateConfirmButtonVisibility);
        $(document).on('change', '#delivery_zone_id', function () {
            const fee = parseFloat($(this).find('option:selected').data('fee') || 0) || 0;
            window.posDeliveryState.zoneId = $(this).val() || null;
            window.posDeliveryState.zoneName = $(this).find('option:selected').text() || '';
            window.posDeliveryState.fee = fee;
            if (window.posDeliveryState.confirmed) {
                syncHiddenFieldsToForm();
                renderDeliveryBar();
                renderDeliveryFeeRow();
            }
        });

        $('#customer_phone').on('input', function () {
            const phone = $(this).val().trim();
            $(this).removeClass('border-success border-info border-danger border-warning');
            if (phone.length < 3) {
                $('#customer_result').html('');
                $('#confirmOrderBtn').hide();
                lastSearchedPhone = '';
                return;
            }
            if (phone === lastSearchedPhone) {
                return;
            }
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function () {
                if (phone.length >= 3 && phone !== lastSearchedPhone) {
                    lastSearchedPhone = phone;
                    searchCustomerDynamic(phone);
                }
            }, 500);
        });

        $('#deliveryModal').on('hidden.bs.modal', function () {
            if (modalDismissConfirmed) {
                modalDismissConfirmed = false;
                return;
            }
            if (!window.posDeliveryState.confirmed) {
                clearDeliveryFormFields();
                $('#age1').prop('checked', true).trigger('change');
            }
        });

        $(document).on('click', '#posDeliveryAddBtn, #posDeliveryEditBtn', function () {
            window.openDeliveryModal();
        });
        $(document).on('click', '#posDeliveryClearBtn', function () {
            clearDeliverySession(true);
        });

        function refreshDeliveryPendingBadge() {
            $.getJSON('ajax/delivery_pending_count.php').done(function (response) {
                const count = parseInt((response && response.pending_count) || 0, 10) || 0;
                const $badge = $('#posDeliveryPendingBadge');
                if (count > 0) {
                    $badge.text(count).removeClass('d-none');
                } else {
                    $badge.addClass('d-none').text('0');
                }
            });
        }

        $('input[name="age"]').on('change', function () {
            window.posDeliveryOnModeChange($(this).val());
        });

        refreshDeliveryPendingBadge();
        setInterval(refreshDeliveryPendingBadge, 30000);
    });
})(window, window.jQuery);
