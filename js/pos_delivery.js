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
        addressId: null,
        zoneId: null,
        zoneName: '',
        fee: '0.00',
        workerId: null,
        workerName: '',
        collectionMode: 'prepaid',
        isExistingClient: false,
    };

    function deliveryMoney(value) {
        if (!window.POSOrderApi || typeof window.POSOrderApi.decimalString !== 'function') {
            throw new Error('POS_MONEY_API_REQUIRED');
        }
        return window.POSOrderApi.decimalString(value, 2, '0');
    }

    function deliveryMoneyIsPositive(value) {
        return window.POSOrderApi.compareDecimalStrings(deliveryMoney(value), '0.00', 2) > 0;
    }

    window.posDeliveryState.fee = deliveryMoney(window.posDeliveryState.fee);

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
            workerId: null,
            workerName: '',
            collectionMode: window.posDeliveryState.collectionMode || 'prepaid',
        };
    }

    function preferredAddress(customer) {
        const addresses = (customer && Array.isArray(customer.addresses)) ? customer.addresses : [];
        return addresses.find(function (address) { return !!address.is_default; }) || addresses[0] || null;
    }

    function selectedZone() {
        const $select = $('#delivery_zone_id');
        const $option = $select.find('option:selected');
        return {
            id: parseInt($select.val(), 10) || null,
            name: ($option.data('name') || $option.text() || '').trim(),
            fee: deliveryMoney($option.attr('data-fee') || '0'),
        };
    }

    function isCustomerFormComplete() {
        const values = getFieldValues();
        const zoneId = $('#delivery_zone_id').val();
        return values.phone.length >= 10 && values.name !== '' && values.address !== '' && !!zoneId;
    }

    function csrfPayload(extra) {
        const payload = Object.assign({}, extra || {});
        if (window.POSMAIN_CSRF_TOKEN) {
            payload.csrf_token = window.POSMAIN_CSRF_TOKEN;
        }
        return payload;
    }

    function updateConfirmButtonVisibility() {
        if (isCustomerFormComplete()) {
            $('#confirmOrderBtn').show();
        } else {
            $('#confirmOrderBtn').hide();
        }
    }

    function removeDeliveryHiddenFields() {
        $('#posForm input[name="delivery_customer_name"], #posForm input[name="delivery_customer_phone"], #posForm input[name="delivery_customer_address"], #posForm input[name="delivery_zone_id"], #posForm input[name="delivery_zone_name"], #posForm input[name="delivery_fee"], #posForm input[name="delivery_worker_id"], #posForm input[name="collection_mode"], #posForm input[name="courier_source"]').remove();
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
            delivery_zone_id: state.zoneId || '',
            delivery_zone_name: state.zoneName || '',
            delivery_fee: deliveryMoney(state.fee),
            delivery_worker_id: state.workerId || '',
            collection_mode: state.collectionMode || 'prepaid',
            courier_source: 'in_house',
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
            const feeLabel = deliveryMoneyIsPositive(state.fee) ? ' — رسوم: ' + deliveryMoney(state.fee) : '';
            const workerLabel = state.workerName ? ' — ' + escapeHtml(state.workerName) : ' — التعيين لاحقاً';
            const collectionLabel = state.collectionMode === 'cod' ? ' — تحصيل عند التسليم' : '';
            $bar.html(
                '<div class="pos-delivery-bar pos-delivery-bar--confirmed">' +
                '<span class="pos-delivery-bar__badge"><i class="fas fa-motorcycle me-1"></i>دليفري</span>' +
                '<span class="pos-delivery-bar__summary">' +
                escapeHtml(state.name) + ' — ' + escapeHtml(phoneShort) + ' — ' + escapeHtml(state.address) +
                zoneLabel + feeLabel + workerLabel + collectionLabel +
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
        if (!isDeliveryMode() || !window.posDeliveryState.confirmed || !deliveryMoneyIsPositive(window.posDeliveryState.fee)) {
            if (typeof window.recalculateOrderTotals === 'function') {
                window.recalculateOrderTotals();
            }
            return;
        }
        const fee = deliveryMoney(window.posDeliveryState.fee);
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
        const zone = selectedZone();
        window.posDeliveryState = {
            confirmed: true,
            clientId: data.client_id || data.id || null,
            phone: data.phone,
            name: data.name,
            address: data.address,
            addressId: data.addressId || window.posDeliveryState.addressId || null,
            zoneId: data.zoneId || zone.id || window.posDeliveryState.zoneId || null,
            zoneName: data.zoneName || zone.name || window.posDeliveryState.zoneName || '',
            fee: deliveryMoney(data.fee != null ? data.fee : (zone.fee || window.posDeliveryState.fee || '0')),
            workerId: null,
            workerName: '',
            collectionMode: data.collectionMode || window.posDeliveryState.collectionMode || 'prepaid',
            isExistingClient: !!data.isExistingClient,
        };
        syncHiddenFieldsToForm();
        renderDeliveryBar();
        renderDeliveryFeeRow();
        if (typeof window.posCustomerAttach === 'function' && (data.client_id || data.id)) {
            const customerId = data.client_id || data.id;
            if (window.posCustomerState && window.posCustomerState.customerId === customerId) {
                return;
            }
            $.getJSON('ajax/pos_customer_profile.php', { id: customerId }, function (response) {
                if (response && response.success && response.customer) {
                    window.posCustomerAttach(response.customer);
                } else {
                    window.posCustomerAttach({
                        id: customerId,
                        display_name: data.name,
                        primary_phone: data.phone,
                        addresses: data.address ? [{
                            id: data.addressId || null,
                            address_text: data.address,
                            zone_id: data.zoneId || null,
                            is_default: true,
                        }] : [],
                        stats: {},
                    });
                }
            });
        }
    }

    window.posDeliveryHydrateFromOrder = function (data) {
        if (!data || data.confirmed !== true) {
            return false;
        }

        const zoneId = parseInt(data.zone_id, 10) || null;
        const name = String(data.name || '').trim();
        const phone = String(data.phone || '').trim();
        const address = String(data.address || '').trim();
        if (!zoneId || name === '' || phone === '' || address === '') {
            return false;
        }

        applyConfirmedCustomer({
            client_id: parseInt(data.client_id, 10) || null,
            phone: phone,
            name: name,
            address: address,
            zoneId: zoneId,
            zoneName: String(data.zone_name || '').trim(),
            fee: Math.max(0, parseFloat(data.fee || 0) || 0),
            workerId: parseInt(data.worker_id, 10) || null,
            collectionMode: data.collection_mode === 'cod' ? 'cod' : 'prepaid',
            isExistingClient: (parseInt(data.client_id, 10) || 0) > 0,
        });
        return true;
    };

    function clearDeliverySession(revertMode) {
        window.posDeliveryState = {
            confirmed: false,
            clientId: null,
            phone: '',
            name: '',
            address: '',
            addressId: null,
            zoneId: null,
            zoneName: '',
            fee: '0.00',
            workerId: null,
            workerName: '',
            collectionMode: 'prepaid',
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

    // A completed save/payment starts a new cashier intent. Delivery customer and
    // fee state must not leak into the empty cart that follows the commit.
    window.posDeliveryResetAfterCommit = function () {
        clearDeliverySession(false);
    };

    function clearDeliveryFormFields() {
        $('#customer_phone').val('').removeClass('border-success border-info border-danger border-warning')
            .removeAttr('placeholder');
        $('#customer_result').html('');
        $('#saveCustomerBtn').html('<i class="fas fa-save me-1"></i>حفظ').show();
        $('#confirmOrderBtn').hide();
        lastSearchedPhone = '';
        if (searchTimeout) {
            clearTimeout(searchTimeout);
            searchTimeout = null;
        }
    }

    function loadDeliveryZones(selectedZoneId) {
        const $select = $('#delivery_zone_id');
        if (!$select.length) {
            return;
        }
        $.getJSON('ajax/delivery_zones_list.php')
            .done(function (response) {
                const zones = (response && response.zones) || [];
                let html = '<option value=""></option>';
                zones.forEach(function (zone) {
                    const fee = deliveryMoney(zone.fee);
                    html += '<option value="' + zone.id + '" data-name="' + escapeHtml(zone.name) + '" data-fee="' + fee + '">' +
                        escapeHtml(zone.name) + ' (' + fee + ' ج.م)</option>';
                });
                $select.html(html);
                const wantedZoneId = parseInt(selectedZoneId, 10) || null;
                if (wantedZoneId && $select.find('option[value="' + wantedZoneId + '"]').length) {
                    $select.val(String(wantedZoneId)).trigger('change');
                }
                updateConfirmButtonVisibility();
            })
            .fail(function () {
                $select.html('<option value=""></option>');
            });
    }

    function showNewCustomerForm() {
        const currentPhone = $('#customer_phone').val().trim();
        window.posDeliveryState.clientId = null;
        window.posDeliveryState.addressId = null;
        window.posDeliveryState.zoneId = null;
        window.posDeliveryState.zoneName = '';
        window.posDeliveryState.fee = '0.00';
        window.posDeliveryState.isExistingClient = false;
        $('#customer_result').html(
            '<div class="alert alert-info mb-3"><i class="fas fa-user-plus me-2"></i>عميل جديد - يرجى إدخال بياناته</div>' +
            '<div class="mb-3"><label class="form-label fw-bold">رقم الموبايل</label>' +
            '<input type="text" class="form-control" id="customer_phone_display" value="' + escapeHtml(currentPhone) + '" readonly></div>' +
            '<div class="mb-3"><label class="form-label fw-bold">اسم العميل</label>' +
            '<input type="text" class="form-control" id="customer_name"></div>' +
            '<div class="mb-3"><label class="form-label fw-bold">العنوان</label>' +
            '<textarea class="form-control" id="customer_address" rows="2"></textarea></div>' +
            '<div class="mb-3"><label class="form-label fw-bold">منطقة التوصيل</label>' +
            '<select class="form-select" id="delivery_zone_id"><option value=""></option></select></div>'
        );
        $('#saveCustomerBtn').html('<i class="fas fa-save me-1"></i>حفظ');
        loadDeliveryZones();
        updateConfirmButtonVisibility();
    }

    function showExistingCustomerForm(customer, phone) {
        const addressRecord = preferredAddress(customer);
        const address = addressRecord ? addressRecord.address_text : '';
        $('#customer_result').html(
            '<div class="alert alert-success mb-3"><i class="fas fa-check-circle me-2"></i>تم العثور على العميل</div>' +
            '<div class="mb-3"><label class="form-label fw-bold">رقم الموبايل</label>' +
            '<input type="text" class="form-control" id="customer_phone_display" value="' + escapeHtml(phone) + '" readonly></div>' +
            '<div class="mb-3"><label class="form-label fw-bold">اسم العميل</label>' +
            '<input type="text" class="form-control" id="customer_name" value="' + escapeHtml(customer.display_name || '') + '"></div>' +
            '<div class="mb-3"><label class="form-label fw-bold">العنوان</label>' +
            '<textarea class="form-control" id="customer_address" rows="2">' + escapeHtml(address) + '</textarea></div>' +
            '<div class="mb-3"><label class="form-label fw-bold">منطقة التوصيل</label>' +
            '<select class="form-select" id="delivery_zone_id"><option value=""></option></select></div>'
        );
        window.posDeliveryState.clientId = customer.id;
        window.posDeliveryState.addressId = addressRecord ? addressRecord.id : null;
        window.posDeliveryState.zoneId = addressRecord ? addressRecord.zone_id : null;
        window.posDeliveryState.zoneName = '';
        window.posDeliveryState.fee = '0.00';
        window.posDeliveryState.isExistingClient = true;
        $('#saveCustomerBtn').html('<i class="fas fa-save me-1"></i>حفظ التعديل');
        loadDeliveryZones(addressRecord ? addressRecord.zone_id : null);
        updateConfirmButtonVisibility();
    }

    function searchCustomerDynamic(phone) {
        $('#customer_phone').addClass('border-warning');
        $('#customer_result').html(
            '<div class="text-center py-2"><div class="spinner-border spinner-border-sm text-primary"></div>' +
            '<small class="d-block mt-1 text-muted">جاري البحث عن العميل...</small></div>'
        );

        $.ajax({
            url: 'ajax/pos_customer_search.php',
            method: 'GET',
            dataType: 'json',
            data: { phone: phone },
        }).done(function (response) {
            if ($('#customer_phone').val().trim() !== phone) {
                return;
            }
            $('#customer_phone').removeClass('border-warning');
            const customer = response && response.exact ? response.exact : null;
            if (response && response.success && customer) {
                $('#customer_phone').addClass('border-success');
                showExistingCustomerForm(customer, phone);
            } else {
                $('#customer_phone').addClass('border-info');
                showNewCustomerForm();
            }
        }).fail(function () {
            if ($('#customer_phone').val().trim() !== phone) {
                return;
            }
            $('#customer_phone').removeClass('border-warning').addClass('border-danger');
            showNewCustomerForm();
        });
    }

    function persistCustomerThenConfirm() {
        const values = getFieldValues();
        if (!isCustomerFormComplete()) {
            Swal.fire({ icon: 'warning', title: 'تنبيه', text: 'يرجى ملء الاسم والهاتف والعنوان ومنطقة التوصيل' });
            return;
        }

        const zone = selectedZone();
        const addressId = parseInt(window.posDeliveryState.addressId, 10) || null;
        const addressPayload = {
            address_text: values.address,
            zone_id: zone.id,
            is_default: true,
        };
        if (addressId) {
            addressPayload.id = addressId;
        }
        const payload = {
            id: window.posDeliveryState.clientId || undefined,
            display_name: values.name,
            phones: [{ phone: values.phone, is_primary: true }],
            addresses: [addressPayload],
        };

        $.ajax({
            url: 'ajax/pos_customer_save.php',
            method: 'POST',
            dataType: 'json',
            data: csrfPayload({ payload: JSON.stringify(payload) }),
        }).done(function (response) {
            if (!response || !response.success || !response.customer) {
                Swal.fire({
                    icon: 'error',
                    title: 'خطأ',
                    text: (response && response.message) ? response.message : 'فشل حفظ بيانات العميل',
                });
                return;
            }
            modalDismissConfirmed = true;
            const savedAddress = preferredAddress(response.customer)
                || (response.customer.addresses || []).find(function (address) {
                    return address.address_text === values.address && parseInt(address.zone_id, 10) === zone.id;
                });
            applyConfirmedCustomer({
                client_id: response.customer.id,
                phone: values.phone,
                name: values.name,
                address: values.address,
                addressId: savedAddress ? savedAddress.id : addressId,
                zoneId: zone.id,
                zoneName: zone.name,
                fee: zone.fee,
                isExistingClient: true,
                workerId: values.workerId,
                workerName: values.workerName,
                collectionMode: values.collectionMode,
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
            let message = 'تعذر حفظ بيانات العميل';
            const response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
            if (response && response.message) {
                message = response.message;
            } else if (xhr && xhr.responseText) {
                try {
                    const parsed = JSON.parse(xhr.responseText);
                    message = parsed.message || parsed.error || message;
                } catch (ignored) {}
            }
            if (message === 'SCHEMA_MIGRATIONS_PENDING' || message === 'POS_CUSTOMER_SCHEMA_MIGRATIONS_PENDING') {
                message = 'يلزم تطبيق تحديث قاعدة بيانات العملاء قبل الحفظ. اطلب من المدير تشغيل التحديثات ثم أعد المحاولة.';
            } else if (message === 'CSRF_INVALID') {
                message = 'انتهت صلاحية جلسة العمل. حدّث الصفحة ثم حاول مرة أخرى.';
            }
            Swal.fire({
                icon: 'error',
                title: 'تعذر حفظ العميل',
                text: message,
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
                '<select class="form-select" id="delivery_zone_id"><option value=""></option></select></div>'
            );
            loadDeliveryZones(window.posDeliveryState.zoneId);
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
        const state = window.posDeliveryState;
        return !!state.confirmed
            && !!state.name
            && !!state.phone
            && !!state.address
            && !!state.zoneId;
    };

    window.posCustomerSyncDeliveryFromProfile = function (profile) {
        if (!isDeliveryMode() || !profile) {
            return;
        }
        const defaultAddress = (profile.addresses || []).find(function (a) { return a.is_default; })
            || (profile.addresses || [])[0];
        const profileZoneId = defaultAddress ? (parseInt(defaultAddress.zone_id, 10) || null) : null;
        const canKeepConfirmation = window.posDeliveryState.confirmed
            && String(window.posDeliveryState.clientId || '') === String(profile.id || '')
            && !!profileZoneId
            && parseInt(window.posDeliveryState.zoneId, 10) === profileZoneId;
        window.posDeliveryState.phone = profile.primary_phone || '';
        window.posDeliveryState.name = profile.display_name || '';
        window.posDeliveryState.address = defaultAddress ? defaultAddress.address_text : '';
        window.posDeliveryState.addressId = defaultAddress ? defaultAddress.id : null;
        window.posDeliveryState.zoneId = profileZoneId;
        window.posDeliveryState.clientId = profile.id;
        if (!canKeepConfirmation) {
            $('#deliveryModalHint').text('راجع العنوان ومنطقة التوصيل').removeClass('d-none');
            $('#customer_phone').val(window.posDeliveryState.phone);
            showExistingCustomerForm(profile, window.posDeliveryState.phone);
            $('#deliveryModal').modal('show');
        } else {
            applyConfirmedCustomer({
                client_id: profile.id,
                phone: window.posDeliveryState.phone,
                name: window.posDeliveryState.name,
                address: window.posDeliveryState.address,
                addressId: window.posDeliveryState.addressId,
                zoneId: window.posDeliveryState.zoneId,
                zoneName: window.posDeliveryState.zoneName,
                fee: window.posDeliveryState.fee,
                isExistingClient: true,
            });
        }
    };

    window.posDeliveryGetFee = function () {
        const rawFee = isDeliveryMode() && window.posDeliveryState.confirmed
            ? String(window.posDeliveryState.fee || '0')
            : '0';
        return deliveryMoney(rawFee);
    };

    window.posDeliveryOnModeChange = function (modeValue) {
        if (modeValue === '3') {
            renderDeliveryBar();
            const attached = typeof window.posCustomerGetAttached === 'function'
                ? window.posCustomerGetAttached()
                : null;
            if (attached && attached.profile) {
                window.posCustomerSyncDeliveryFromProfile(attached.profile);
            } else if (!window.posDeliveryState.confirmed) {
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
            const fee = deliveryMoney($(this).find('option:selected').attr('data-fee') || '0');
            window.posDeliveryState.zoneId = $(this).val() || null;
            window.posDeliveryState.zoneName = $(this).find('option:selected').data('name')
                || $(this).find('option:selected').text()
                || '';
            window.posDeliveryState.fee = fee;
            updateConfirmButtonVisibility();
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
                // Keep any transferred cart items; skip the transfer toast on this revert.
                window.__posModeSwitchKeepCart = true;
                window.__posModeSwitchSilent = true;
                $('#age1').prop('checked', true).trigger('change');
            }
        });

        $(document).on('click', '#posDeliveryAddBtn, #posDeliveryEditBtn', function () {
            window.openDeliveryModal();
        });
        $(document).on('click', '#posDeliveryClearBtn', function () {
            clearDeliverySession(true);
        });

        $('input[name="age"]').on('change', function () {
            window.posDeliveryOnModeChange($(this).val());
        });

        if (window.POSMAIN_EDIT_DELIVERY) {
            window.posDeliveryHydrateFromOrder(window.POSMAIN_EDIT_DELIVERY);
        } else if (isDeliveryMode()) {
            renderDeliveryBar();
        }
    });
})(window, window.jQuery);
