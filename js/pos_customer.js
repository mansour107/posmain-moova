/**
 * POS phone-first customer strip for cashier order creation.
 */
(function (window, $) {
    'use strict';

    if (!$) {
        return;
    }

    window.posCustomerState = window.posCustomerState || {
        attached: false,
        customerId: null,
        displayName: '',
        primaryPhone: '',
        profile: null,
    };

    let searchTimer = null;
    let lastSearchPhone = '';

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function csrfPayload(extra) {
        const payload = Object.assign({}, extra || {});
        if (window.POSMAIN_CSRF_TOKEN) {
            payload.csrf_token = window.POSMAIN_CSRF_TOKEN;
        }
        return payload;
    }

    function removeHiddenFields() {
        $('#posForm input[name="pos_customer_id"]').remove();
    }

    function syncHiddenFields() {
        removeHiddenFields();
        if (!window.posCustomerState.attached || !window.posCustomerState.customerId) {
            return;
        }
        const id = window.posCustomerState.customerId;
        $('<input>', { type: 'hidden', name: 'pos_customer_id', value: id }).appendTo('#posForm');
    }

    function formatMoney(amount) {
        return (parseFloat(amount) || 0).toFixed(2) + ' ج.م';
    }

    function formatStats(profile) {
        const stats = (profile && profile.stats) || profile || {};
        const parts = [];
        if (stats.orders_count !== undefined) {
            parts.push('طلبات مدفوعة: ' + (parseInt(stats.orders_count, 10) || 0));
        }
        if (stats.linked_orders !== undefined && parseInt(stats.linked_orders, 10) > 0) {
            parts.push('مرتبطة: ' + (parseInt(stats.linked_orders, 10) || 0));
        }
        if (stats.lifetime_paid !== undefined) {
            parts.push('إجمالي: ' + formatMoney(stats.lifetime_paid));
        }
        if (stats.last_order_at) {
            parts.push('آخر طلب: ' + String(stats.last_order_at).slice(0, 10));
        }
        return parts.join(' · ');
    }

    function showSearchRow() {
        $('#posCustomerAttached, #posCustomerCreate, #posCustomerSuggestions, #posCustomerEditor').addClass('d-none');
        $('#posCustomerSearchRow').removeClass('d-none');
    }

    function renderAttached(profile) {
        window.posCustomerState.attached = true;
        window.posCustomerState.customerId = profile.id;
        window.posCustomerState.displayName = profile.display_name || '';
        window.posCustomerState.primaryPhone = profile.primary_phone || '';
        window.posCustomerState.profile = profile;

        $('#posCustomerChipName').text(profile.display_name || '—');
        $('#posCustomerChipPhone').text(profile.primary_phone || '');
        $('#posCustomerChipStats').text(formatStats(profile));
        $('#posCustomerSearchRow, #posCustomerCreate, #posCustomerSuggestions, #posCustomerEditor').addClass('d-none');
        $('#posCustomerAttached').removeClass('d-none');
        $('#posCustomerPhoneInput').val(profile.primary_phone || '');
        syncHiddenFields();

        if (window.posDeliveryState) {
            window.posDeliveryState.clientId = profile.id;
            window.posDeliveryState.phone = profile.primary_phone || '';
            window.posDeliveryState.name = profile.display_name || '';
            if (profile.addresses && profile.addresses.length) {
                const defaultAddress = profile.addresses.find(function (a) { return a.is_default; }) || profile.addresses[0];
                window.posDeliveryState.address = defaultAddress.address_text || '';
            }
            if (typeof window.posCustomerSyncDeliveryFromProfile === 'function'
                && $('input[name="age"]:checked').val() === '3') {
                window.posCustomerSyncDeliveryFromProfile(profile);
            }
        }
    }

    window.posCustomerAttach = function (profile) {
        if (!profile || !profile.id) {
            return;
        }
        renderAttached(profile);
    };

    window.posCustomerDetach = function () {
        window.posCustomerState.attached = false;
        window.posCustomerState.customerId = null;
        window.posCustomerState.displayName = '';
        window.posCustomerState.primaryPhone = '';
        window.posCustomerState.profile = null;
        removeHiddenFields();
        $('#posCustomerPhoneInput').val('');
        showSearchRow();
    };

    window.posCustomerGetAttached = function () {
        return window.posCustomerState.attached ? Object.assign({}, window.posCustomerState) : null;
    };

    window.posCustomerSyncHiddenFields = syncHiddenFields;

    function renderSuggestions(items) {
        const $box = $('#posCustomerSuggestions');
        if (!items || !items.length) {
            $box.addClass('d-none').empty();
            return;
        }
        let html = '';
        items.forEach(function (item) {
            html += '<button type="button" class="pos-customer-suggestion-item" data-id="' + item.id + '">' +
                '<span>' + escapeHtml(item.display_name) + '</span>' +
                '<small>' + escapeHtml(item.phone) + '</small>' +
                '</button>';
        });
        $box.html(html).removeClass('d-none');
    }

    function showCreatePanel(phone) {
        $('#posCustomerAttached, #posCustomerSuggestions, #posCustomerEditor').addClass('d-none');
        $('#posCustomerCreate').removeClass('d-none');
        $('#posCustomerCreateName').val('').focus();
        $('#posCustomerCreateNotes').val('');
        $('#posCustomerPhoneInput').val(phone || $('#posCustomerPhoneInput').val());
    }

    function searchPhone(phone) {
        const normalized = String(phone || '').replace(/\D+/g, '');
        if (normalized.length < 3) {
            $('#posCustomerSuggestions, #posCustomerCreate').addClass('d-none');
            return;
        }

        $.ajax({
            url: 'ajax/pos_customer_search.php',
            method: 'GET',
            dataType: 'json',
            data: { phone: phone },
            success: function (response) {
                if (!response || !response.success) {
                    return;
                }
                if (response.exact) {
                    renderAttached(response.exact);
                    return;
                }
                if (response.suggestions && response.suggestions.length) {
                    renderSuggestions(response.suggestions);
                    $('#posCustomerCreate').addClass('d-none');
                    return;
                }
                if (normalized.length >= 10) {
                    showCreatePanel(phone);
                }
            }
        });
    }

    function loadProfile(customerId, callback) {
        $.getJSON('ajax/pos_customer_profile.php', { id: customerId }, function (response) {
            if (response && response.success && response.customer) {
                callback(response.customer);
            }
        });
    }

    function saveCustomer(payload, callback) {
        $.ajax({
            url: 'ajax/pos_customer_save.php',
            method: 'POST',
            dataType: 'json',
            data: csrfPayload({ payload: JSON.stringify(payload) }),
            success: function (response) {
                if (response && response.success && response.customer) {
                    callback(response.customer);
                    return;
                }
                alert((response && response.message) || 'تعذر حفظ بيانات العميل');
            },
            error: function (xhr) {
                let message = 'تعذر حفظ بيانات العميل';
                try {
                    const json = JSON.parse(xhr.responseText || '{}');
                    if (json.message) {
                        message = json.message;
                    }
                } catch (e) {}
                alert(message);
            }
        });
    }

    function renderEditor(profile) {
        const phonesHtml = (profile.phones || []).map(function (phone) {
            return '<div class="pos-customer-editor-phone">' +
                '<span>' + escapeHtml(phone.phone) + (phone.is_primary ? ' <i class="fas fa-star text-warning"></i>' : '') + '</span>' +
                (!phone.is_primary ? '<button type="button" class="btn btn-link btn-sm set-primary-phone" data-id="' + phone.id + '">تعيين أساسي</button>' : '') +
                '</div>';
        }).join('');

        const addressesHtml = (profile.addresses || []).map(function (addr) {
            return '<div class="pos-customer-editor-address">' + escapeHtml(addr.address_text) + '</div>';
        }).join('');

        $('#posCustomerEditor').html(
            '<div class="mb-2"><label class="form-label small">الاسم</label>' +
            '<input type="text" class="form-control form-control-sm" id="posCustomerEditName" value="' + escapeHtml(profile.display_name) + '"></div>' +
            '<div class="mb-2"><label class="form-label small">ملاحظات</label>' +
            '<textarea class="form-control form-control-sm" id="posCustomerEditNotes" rows="2">' + escapeHtml(profile.notes || '') + '</textarea></div>' +
            '<div class="mb-2"><label class="form-label small">أرقام الهاتف</label>' + phonesHtml +
            '<div class="input-group input-group-sm mt-1">' +
            '<input type="tel" class="form-control" id="posCustomerAddPhone" placeholder="إضافة رقم">' +
            '<button type="button" class="btn btn-outline-secondary" id="posCustomerAddPhoneBtn">إضافة</button></div></div>' +
            (addressesHtml ? '<div class="mb-2"><label class="form-label small">العناوين</label>' + addressesHtml + '</div>' : '') +
            '<button type="button" class="btn btn-sm btn-primary w-100" id="posCustomerSaveEditBtn">حفظ</button>'
        ).removeClass('d-none');
        $('#posCustomerAttached, #posCustomerCreate, #posCustomerSuggestions, #posCustomerSearchRow').addClass('d-none');
    }

    $(document).ready(function () {
        $('#posCustomerPhoneInput').on('input', function () {
            const phone = $(this).val();
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                if (phone === lastSearchPhone) {
                    return;
                }
                lastSearchPhone = phone;
                if (window.posCustomerState.attached) {
                    return;
                }
                searchPhone(phone);
            }, 300);
        });

        $('#posCustomerDetachBtn').on('click', function () {
            window.posCustomerDetach();
        });

        $('#posCustomerCreateBtn').on('click', function () {
            const phone = $('#posCustomerPhoneInput').val().trim();
            const name = $('#posCustomerCreateName').val().trim();
            const notes = $('#posCustomerCreateNotes').val().trim();
            if (!phone || !name) {
                alert('أدخل رقم الهاتف واسم العميل');
                return;
            }
            saveCustomer({
                display_name: name,
                notes: notes,
                phones: [{ phone: phone, is_primary: true }],
            }, renderAttached);
        });

        $(document).on('click', '.pos-customer-suggestion-item', function () {
            const id = parseInt($(this).data('id'), 10) || 0;
            if (!id) {
                return;
            }
            loadProfile(id, renderAttached);
        });

        $('#posCustomerEditBtn').on('click', function () {
            const profile = window.posCustomerState.profile;
            if (!profile) {
                return;
            }
            renderEditor(profile);
        });

        $(document).on('click', '#posCustomerSaveEditBtn', function () {
            const profile = window.posCustomerState.profile;
            if (!profile) {
                return;
            }
            saveCustomer({
                id: profile.id,
                display_name: $('#posCustomerEditName').val().trim(),
                notes: $('#posCustomerEditNotes').val().trim(),
                phones: profile.phones || [],
                addresses: profile.addresses || [],
            }, function (updated) {
                renderAttached(updated);
            });
        });

        $(document).on('click', '#posCustomerAddPhoneBtn', function () {
            const profile = window.posCustomerState.profile;
            const newPhone = $('#posCustomerAddPhone').val().trim();
            if (!profile || !newPhone) {
                return;
            }
            const phones = (profile.phones || []).map(function (p) {
                return { phone: p.phone, is_primary: p.is_primary, label: p.label || '' };
            });
            phones.push({ phone: newPhone, is_primary: phones.length === 0 });
            saveCustomer({
                id: profile.id,
                display_name: profile.display_name,
                notes: profile.notes || '',
                phones: phones,
                addresses: profile.addresses || [],
            }, function (updated) {
                renderEditor(updated);
                window.posCustomerState.profile = updated;
            });
        });

        $(document).on('click', '.set-primary-phone', function () {
            const profile = window.posCustomerState.profile;
            const phoneId = parseInt($(this).data('id'), 10) || 0;
            if (!profile || !phoneId) {
                return;
            }
            const phones = (profile.phones || []).map(function (p) {
                return { phone: p.phone, is_primary: parseInt(p.id, 10) === phoneId, label: p.label || '' };
            });
            saveCustomer({
                id: profile.id,
                display_name: profile.display_name,
                notes: profile.notes || '',
                phones: phones,
                addresses: profile.addresses || [],
            }, function (updated) {
                renderAttached(updated);
            });
        });
    });
})(window, window.jQuery);
