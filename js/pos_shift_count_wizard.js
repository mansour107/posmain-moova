(function (window, $) {
    'use strict';

    if (!$) {
        return;
    }

    const OPEN_ERROR_MESSAGES = {
        OPENING_BASELINE_REQUIRED: 'يتطلب تهيئة العهد من المدير قبل افتتاح الدرج',
        BRANCH_DRAWER_ALREADY_OPEN: 'الدرج مفتوح بالفعل لدى كاشير آخر',
        HANDOVER_NOT_ENABLED: 'نظام تسليم الدرج غير مفعّل',
        OPEN_COUNT_NOT_STARTED: 'لم يبدأ عد الافتتاح — أعد تحميل الصفحة',
        OPEN_COUNT_MAX_ATTEMPTS: 'تم تجاوز الحد الأقصى لمحاولات العد',
        COUNTED_AMOUNT_INVALID: 'المبلغ غير صالح',
        COUNTED_AMOUNT_REQUIRED: 'الرجاء إدخال المبلغ',
        AUTH_REQUIRED: 'يلزم تسجيل الدخول',
        POS_UNLOCK_REQUIRED: 'يلزم فتح نقطة البيع أولاً',
        FORCE_CLOSE_REASON_REQUIRED: 'يجب إدخال سبب الاستلام',
        BLOCKING_SESSION_MISMATCH: 'جلسة الدرج المفتوحة تغيّرت — أعد المحاولة',
        CANNOT_TAKEOVER_OWN_SESSION: 'لا يمكن استلام جلستك أنت',
        DRAWER_SESSION_SCOPE_MISMATCH: 'جلسة الدرج خارج نطاق الفرع الحالي',
        DRAWER_SESSION_NOT_OPEN: 'الوردية غير متاحة — حدّث الصفحة وأعد المحاولة',
        DRAWER_SESSION_REQUIRED: 'لا توجد وردية مفتوحة',
        MANAGER_APPROVAL_REQUIRED: 'يتطلب اعتماد مدير',
        CSRF_INVALID: 'انتهت صلاحية الجلسة — أعد تحميل الصفحة',
        IDEMPOTENCY_CONFLICT: 'طلب مكرر — أعد المحاولة',
        PERMISSION_DENIED: 'ليس لديك صلاحية لهذا الإجراء',
        SCHEMA_MIGRATIONS_PENDING: 'تحديث قاعدة البيانات مطلوب قبل إتمام العملية',
        DRAWER_CLOSE_SUMMARY_SCHEMA_MISSING: 'تحديث إغلاق الورديات غير مثبت — تواصل مع مسؤول النظام',
    };

    const wizard = {
        requestKeys: {},
        blockingSession: null,

        createIdempotencyKey: function (scope) {
            const suffix = (typeof crypto !== 'undefined' && crypto.randomUUID)
                ? crypto.randomUUID()
                : (Date.now().toString(36) + ':' + Math.random().toString(36).slice(2));

            return scope + ':' + suffix;
        },

        getIdempotencyKey: function (scope) {
            if (!this.requestKeys[scope]) {
                this.requestKeys[scope] = this.createIdempotencyKey(scope);
            }

            return this.requestKeys[scope];
        },

        clearIdempotencyKey: function (scope) {
            delete this.requestKeys[scope];
        },

        parseAjaxPayload: function (xhr, response) {
            if (response && typeof response === 'object') {
                return response;
            }
            if (xhr && xhr.responseJSON && typeof xhr.responseJSON === 'object') {
                return xhr.responseJSON;
            }
            if (xhr && typeof xhr.responseText === 'string' && xhr.responseText.trim() !== '') {
                try {
                    const parsed = JSON.parse(xhr.responseText);
                    if (parsed && typeof parsed === 'object') {
                        return parsed;
                    }
                } catch (ignored) {
                }
            }

            return null;
        },

        openErrorMessage: function (code, fallback) {
            if (code && OPEN_ERROR_MESSAGES[code]) {
                return OPEN_ERROR_MESSAGES[code];
            }

            return fallback || 'تعذر إتمام العملية';
        },

        closeState: {
            step: 'summary',
            closeToken: '',
            drawerSessionId: 0,
            countedCash: 0,
            matched: false,
            attemptNumber: 0,
            handoverEnabled: false,
        },

        initOpenOverlay: function () {
            const $overlay = $('#pshOpenOverlay');
            if (!$overlay.length) {
                return;
            }

            this.bindOpenOverlay($overlay);
            if ($overlay.attr('data-psh-open-denied') === '1') {
                this.showOpenPermissionDenied($overlay);
                return;
            }
            this.beginOpenCount($overlay);
        },

        showOpenPermissionDenied: function ($overlay) {
            $('#pshOpenPermissionDenied').removeClass('psh-hidden');
            $('#pshOpenBranchBlocked').addClass('psh-hidden');
            $('#pshOpenBaselineRequired').addClass('psh-hidden');
            $('#pshOpenCountStep').addClass('psh-hidden');
            $('#pshOpenUnassignedNote').addClass('psh-hidden');
            $('#pshOpenVariance').addClass('psh-hidden');
            $overlay.find('[data-psh-open-submit]').prop('disabled', true);
            $('#pshOpenAttemptLabel').text('هذه الشاشة للكاشير أو المدير فقط');
        },

        bindOpenOverlay: function ($overlay) {
            const self = this;

            $overlay.find('[data-psh-open-submit]').on('click', function () {
                self.submitOpenCount($overlay);
            });

            $overlay.find('[data-psh-open-takeover]').on('click', function () {
                self.submitTakeover($overlay);
            });

            $overlay.find('#pshOpenAmount').on('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    self.submitOpenCount($overlay);
                }
            });
        },

        beginOpenCount: function ($overlay) {
            const self = this;

            return $.ajax({
                url: 'do/do_begin_shift_open_count.php',
                method: 'GET',
                dataType: 'json',
            }).done(function (response) {
                if (response && response.success && response.data && response.data.has_unassigned) {
                    $('#pshOpenUnassignedNote').removeClass('psh-hidden');
                }
                self.showOpenCountStep($overlay);
            }).fail(function (xhr) {
                const payload = self.parseAjaxPayload(xhr);
                self.handleOpenBeginError($overlay, payload);
            });
        },

        handleOpenBeginError: function ($overlay, payload) {
            const err = (payload && (payload.error || payload.code)) || '';
            if (err === 'OPENING_BASELINE_REQUIRED') {
                $('#pshOpenBaselineRequired').removeClass('psh-hidden');
                $('#pshOpenBranchBlocked').addClass('psh-hidden');
                $('#pshOpenPermissionDenied').addClass('psh-hidden');
                $('#pshOpenCountStep').addClass('psh-hidden');
                $overlay.find('[data-psh-open-submit]').prop('disabled', true);
                return;
            }
            if (err === 'BRANCH_DRAWER_ALREADY_OPEN') {
                this.showBranchBlocked($overlay, payload && payload.blocking_session);
                return;
            }
            if (err === 'PERMISSION_DENIED') {
                this.showOpenPermissionDenied($overlay);
                return;
            }
            if (err) {
                $('#pshOpenMessage').removeClass('psh-hidden is-success is-info').addClass('is-warn')
                    .text(this.openErrorMessage(err, err));
            }
        },

        showBranchBlocked: function ($overlay, blockingSession) {
            this.blockingSession = blockingSession || null;
            const name = (blockingSession && blockingSession.cashier_name) || 'كاشير آخر';
            const openedAt = (blockingSession && blockingSession.opened_at) || '';
            let text = 'الدرج مفتوح بالفعل لدى ' + name + '.';
            if (openedAt) {
                text += ' منذ ' + openedAt + '.';
            }
            text += ' يجب إغلاق تلك الجلسة أولاً، أو طلب مساعدة المدير لاستلام الدرج.';

            $('#pshOpenBranchBlockedText').text(text);
            $('#pshOpenBranchBlocked').removeClass('psh-hidden');
            $('#pshOpenBaselineRequired').addClass('psh-hidden');
            $('#pshOpenPermissionDenied').addClass('psh-hidden');
            $('#pshOpenCountStep').addClass('psh-hidden');
            $('#pshOpenUnassignedNote').addClass('psh-hidden');
            $overlay.find('[data-psh-open-submit]').prop('disabled', true);
            $('#pshOpenAttemptLabel').text('الدرج محجوز — يلزم إغلاق أو استلام');
            $('#pshOpenTakeoverMessage').addClass('psh-hidden').text('');
            $('#pshTakeoverAmount').val('').prop('disabled', false);
            $('#pshTakeoverReason').val('');
            $('#pshTakeoverAttemptLabel').addClass('psh-hidden').text('');
            $('#pshTakeoverVariance').addClass('psh-hidden').removeClass('is-over is-under is-balanced');
            $('#pshTakeoverAmountWrap').removeClass('psh-hidden');
            $('#pshTakeoverReasonWrap').addClass('psh-hidden');
            $overlay.find('[data-psh-open-takeover]').attr('data-phase', 'count').text('تأكيد العد').prop('disabled', false);
            this.takeoverCountState = { started: false, handover: true, finalized: false, countedCash: null };
        },

        showOpenCountStep: function ($overlay) {
            this.blockingSession = null;
            $('#pshOpenBranchBlocked').addClass('psh-hidden');
            $('#pshOpenBaselineRequired').addClass('psh-hidden');
            $('#pshOpenPermissionDenied').addClass('psh-hidden');
            $('#pshOpenCountStep').removeClass('psh-hidden');
            $overlay.find('[data-psh-open-submit]').prop('disabled', false).removeClass('psh-hidden');
            $('#pshOpenAttemptLabel').text('عدّ النقد في الدرج قبل بدء الشيفت');
            setTimeout(function () {
                $('#pshOpenAmount').trigger('focus');
            }, 100);
        },

        submitOpenCount: function ($overlay) {
            const self = this;
            const amount = ($('#pshOpenAmount').val() || '').trim();
            const $message = $('#pshOpenMessage');
            const $btn = $overlay.find('[data-psh-open-submit]');
            const scope = 'pos.shift.submit_open_count';

            if (amount === '') {
                $message.removeClass('psh-hidden is-success is-warn').addClass('is-info').text('الرجاء إدخال المبلغ');
                return;
            }

            $btn.prop('disabled', true);
            $message.addClass('psh-hidden');

            $.ajax({
                url: 'do/do_submit_shift_open_count.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    counted_amount: amount,
                    idempotency_key: this.getIdempotencyKey(scope),
                    csrf_token: window.POSMAIN_SHIFT_OPEN_CSRF_TOKEN || '',
                },
            }).done(function (response) {
                if (!response || !response.success) {
                    if ((response && response.error) === 'OPENING_BASELINE_REQUIRED') {
                        $('#pshOpenBaselineRequired').removeClass('psh-hidden');
                        $('#pshOpenCountStep').addClass('psh-hidden');
                        $btn.prop('disabled', true);
                        return;
                    }
                    if ((response && response.error) === 'BRANCH_DRAWER_ALREADY_OPEN') {
                        self.showBranchBlocked($overlay, response.blocking_session);
                        $btn.prop('disabled', false);
                        return;
                    }
                    $message.removeClass('psh-hidden').addClass('is-warn')
                        .text(self.openErrorMessage(response && response.error, 'تعذر التحقق من العد'));
                    $btn.prop('disabled', false);
                    return;
                }

                const data = response.data || {};
                if (data.status === 'recount') {
                    self.clearIdempotencyKey(scope);
                    $('#pshOpenAttemptLabel').text('محاولة ' + (data.attempt_number || 1) + ' من ' + (data.max_attempts || 2));
                    $message.removeClass('psh-hidden is-info is-success').addClass('is-warn').text(data.message || 'الرجاء إعادة العد بعناية');
                    $('#pshOpenAmount').val('').focus();
                    $btn.prop('disabled', false);
                    return;
                }

                self.clearIdempotencyKey(scope);

                if (data.status === 'opened_with_variance') {
                    self.showOpenVariance($overlay, data);
                    return;
                }

                $overlay.fadeOut(200, function () {
                    $overlay.remove();
                    window.location.reload();
                });
            }).fail(function (xhr) {
                const payload = self.parseAjaxPayload(xhr);
                if (payload && payload.error === 'BRANCH_DRAWER_ALREADY_OPEN') {
                    self.showBranchBlocked($overlay, payload.blocking_session);
                    $btn.prop('disabled', false);
                    return;
                }
                if (payload && payload.error === 'OPENING_BASELINE_REQUIRED') {
                    $('#pshOpenBaselineRequired').removeClass('psh-hidden');
                    $('#pshOpenCountStep').addClass('psh-hidden');
                    $btn.prop('disabled', true);
                    return;
                }
                if (payload && payload.error) {
                    $message.removeClass('psh-hidden').addClass('is-warn')
                        .text(self.openErrorMessage(payload.error, payload.error));
                    $btn.prop('disabled', false);
                    return;
                }
                $message.removeClass('psh-hidden').addClass('is-warn').text('خطأ في الاتصال');
                $btn.prop('disabled', false);
            });
        },

        submitTakeover: function ($overlay) {
            const self = this;
            const $message = $('#pshOpenTakeoverMessage');
            const $btn = $overlay.find('[data-psh-open-takeover]');
            const amount = ($('#pshTakeoverAmount').val() || '').trim();
            const reason = ($('#pshTakeoverReason').val() || '').trim();
            const sessionId = this.blockingSession && this.blockingSession.drawer_session_id
                ? Number(this.blockingSession.drawer_session_id)
                : 0;
            const scope = 'pos.shift.takeover_drawer';
            const phase = $btn.attr('data-phase') || 'count';
            const countState = this.takeoverCountState || {
                started: false, handover: true, finalized: false, countedCash: null,
            };
            this.takeoverCountState = countState;

            if (!sessionId) {
                $message.removeClass('psh-hidden is-success is-info').addClass('is-warn')
                    .text('لا توجد جلسة مفتوحة للاستلام');
                return;
            }

            const enterConfirm = function (data) {
                countState.finalized = true;
                countState.countedCash = data && data.counted_cash != null ? data.counted_cash : Number(amount);
                $('#pshTakeoverAttemptLabel').addClass('psh-hidden');
                $('#pshTakeoverAmountWrap').addClass('psh-hidden');
                $('#pshTakeoverReasonWrap').removeClass('psh-hidden');
                $('#pshTakeoverAmount').prop('disabled', true);
                const direction = (data && data.variance_direction) || 'balanced';
                const labels = { over: 'زيادة في الدرج', under: 'عجز في الدرج', balanced: 'العد متطابق' };
                const $variance = $('#pshTakeoverVariance');
                $variance.removeClass('psh-hidden is-over is-under is-balanced').addClass('is-' + direction);
                $('#pshTakeoverVarianceLabel').text(labels[direction] || labels.balanced);
                $('#pshTakeoverVarianceAmount').text(
                    direction === 'balanced'
                        ? '0.00'
                        : (Math.abs(Number((data && data.variance) || 0)).toFixed(2) + ' ج.م')
                );
                $btn.attr('data-phase', 'confirm').text('متابعة وإدخال رمز المدير').prop('disabled', false);
                $message.addClass('psh-hidden');
            };

            const runTakeover = function (approvalId, finalAmount) {
                return $.ajax({
                    url: 'do/do_takeover_drawer_session.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        drawer_session_id: sessionId,
                        counted_amount: finalAmount,
                        reason: reason,
                        manager_approval_id: approvalId || '',
                        idempotency_key: self.getIdempotencyKey(scope),
                        csrf_token: window.POSMAIN_SHIFT_TAKEOVER_CSRF_TOKEN || '',
                    },
                });
            };

            const afterSuccess = function () {
                self.clearIdempotencyKey(scope);
                // Takeover already opens the manager shift from the close-count cash.
                window.location.href = 'pos_barcode.php';
            };

            const afterFailure = function (xhr, response) {
                const payload = self.parseAjaxPayload(xhr, response) || response || {};
                const err = payload.error || payload.code || '';
                $message.removeClass('psh-hidden is-success is-info').addClass('is-warn')
                    .text(err ? self.openErrorMessage(err, err) : 'خطأ في الاتصال');
                $btn.prop('disabled', false);
            };

            if (phase === 'confirm' && countState.finalized) {
                if (reason.length < 3) {
                    $message.removeClass('psh-hidden is-success is-info').addClass('is-warn')
                        .text('يجب إدخال سبب الاستلام');
                    return;
                }
                $btn.prop('disabled', true);
                $message.addClass('psh-hidden');
                self.requestTakeoverApproval(sessionId, reason).done(function (approval) {
                    const approvalId = approval && approval.approval_id;
                    if (!approvalId) {
                        afterFailure(null, { error: 'MANAGER_APPROVAL_REQUIRED' });
                        return;
                    }
                    runTakeover(approvalId, countState.countedCash).done(function (resp) {
                        if (resp && resp.success) {
                            afterSuccess();
                            return;
                        }
                        afterFailure(null, resp);
                    }).fail(function (xhr2) {
                        afterFailure(xhr2);
                    });
                }).fail(function (deny) {
                    if (deny && deny.code === 'OVERRIDE_CANCELLED') {
                        $btn.prop('disabled', false);
                        return;
                    }
                    $message.removeClass('psh-hidden is-success is-info').addClass('is-warn')
                        .text((deny && deny.message) || self.openErrorMessage(deny && deny.code, 'تعذر اعتماد المدير'));
                    $btn.prop('disabled', false);
                });
                return;
            }

            if (amount === '') {
                $message.removeClass('psh-hidden is-success is-warn').addClass('is-info')
                    .text('الرجاء إدخال المبلغ المعدود');
                return;
            }

            $btn.prop('disabled', true);
            $message.addClass('psh-hidden');

            const beginCount = function () {
                return $.ajax({
                    url: 'do/do_begin_takeover_close_count.php',
                    method: 'GET',
                    dataType: 'json',
                    data: { drawer_session_id: sessionId },
                });
            };

            const submitCount = function () {
                return $.ajax({
                    url: 'do/do_submit_takeover_close_count.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        counted_amount: amount,
                        idempotency_key: self.getIdempotencyKey('pos.shift.takeover_close_count'),
                        csrf_token: window.POSMAIN_SHIFT_TAKEOVER_COUNT_CSRF_TOKEN || '',
                    },
                });
            };

            const afterBegin = function (data) {
                countState.started = true;
                countState.handover = true;
                if (data && data.status === 'final_amount_required') {
                    $('#pshTakeoverAttemptLabel').removeClass('psh-hidden')
                        .text('تم استخدام محاولتي العد من 2');
                    $message.removeClass('psh-hidden is-success is-warn').addClass('is-info')
                        .text(data.message || 'أدخل المبلغ النهائي لاعتماده عند الإغلاق.');
                    $btn.text('اعتماد المبلغ النهائي');
                }
                return submitCount();
            };

            (countState.started ? submitCount() : beginCount().then(afterBegin))
                .done(function (resp) {
                    if (!resp || !resp.success) {
                        if ((resp && resp.error) === 'HANDOVER_NOT_ENABLED') {
                            countState.handover = false;
                            enterConfirm({
                                status: 'ready_to_takeover',
                                matched: true,
                                counted_cash: Number(amount),
                                variance: 0,
                                variance_direction: 'balanced',
                            });
                            return;
                        }
                        afterFailure(null, resp);
                        return;
                    }
                    const data = resp.data || {};
                    if (data.status === 'recount') {
                        self.clearIdempotencyKey('pos.shift.takeover_close_count');
                        $('#pshTakeoverAttemptLabel').removeClass('psh-hidden')
                            .text('محاولة ' + (data.attempt_number || 1) + ' من ' + (data.max_attempts || 2));
                        $message.removeClass('psh-hidden is-success is-info').addClass('is-warn')
                            .text(data.message || 'الرجاء إعادة العد بعناية');
                        $('#pshTakeoverAmount').val('').trigger('focus');
                        $btn.prop('disabled', false);
                        return;
                    }
                    self.clearIdempotencyKey('pos.shift.takeover_close_count');
                    enterConfirm(data);
                })
                .fail(function (xhr) {
                    const payload = self.parseAjaxPayload(xhr) || {};
                    if (payload.error === 'HANDOVER_NOT_ENABLED') {
                        countState.handover = false;
                        enterConfirm({
                            status: 'ready_to_takeover',
                            matched: true,
                            counted_cash: Number(amount),
                            variance: 0,
                            variance_direction: 'balanced',
                        });
                        return;
                    }
                    afterFailure(xhr);
                });
        },

        requestTakeoverApproval: function (sessionId, reason) {
            if (!window.POSMAIN || typeof window.POSMAIN.requestManagerOverride !== 'function') {
                return $.Deferred().reject({ code: 'OVERRIDE_UNAVAILABLE' }).promise();
            }

            return window.POSMAIN.requestManagerOverride('pos.shift.force_close', {
                action_type: 'pos.shift.force_close',
                target_type: 'drawer_session',
                target_id: sessionId,
                reason: reason || 'drawer_takeover',
                message: 'أدخل رمزك المكوّن من 4 أرقام لاستلام الدرج',
                require_same_user: true,
                digits: 4,
            });
        },

        showOpenVariance: function ($overlay, data) {
            const direction = data.variance_direction || 'balanced';
            const $variance = $('#pshOpenVariance');
            const labels = {
                over: 'زيادة في الدرج',
                under: 'عجز في الدرج',
                balanced: 'متوازن',
            };
            const attempt = Number(data.attempt_number || data.max_attempts || 2);
            const maxAttempts = Number(data.max_attempts || 2);

            $('#pshOpenAttemptLabel').text('محاولة ' + attempt + ' من ' + maxAttempts);
            $variance.removeClass('is-over is-under is-balanced').addClass('is-' + direction);
            $('#pshOpenVarianceLabel').text(labels[direction] || labels.balanced);
            $('#pshOpenVarianceAmount').text(Math.abs(Number(data.variance || 0)).toFixed(2) + ' ج.م');
            $('#pshOpenCountStep').addClass('psh-hidden');
            $variance.removeClass('psh-hidden');
            $overlay.find('[data-psh-open-submit]').addClass('psh-hidden');
            $overlay.find('[data-psh-open-acknowledge]').removeClass('psh-hidden').off('click').on('click', function () {
                window.location.reload();
            });
        },

        initCloseModal: function () {
            const self = this;
            const $modal = $('#closeShiftModal');
            if (!$modal.length) {
                return;
            }

            $modal.on('show.bs.modal', function () {
                self.resetCloseWizard();
            });

            $modal.find('[data-psh-close-next]').on('click', function () {
                self.goCloseStep('count');
            });

            $modal.find('[data-psh-close-back]').on('click', function () {
                // After any count submit, attempts are sticky server-side — do not
                // let the cashier return to summary and restart the blind count.
                if ((self.closeState.attemptNumber || 0) > 0) {
                    return;
                }
                self.goCloseStep('summary');
            });

            $modal.find('[data-psh-close-submit-count]').on('click', function () {
                self.submitCloseCount();
            });

            $modal.find('[data-psh-close-final]').on('click', function () {
                self.finalizeClose();
            });

            $('#pshCloseAmount').on('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    self.submitCloseCount();
                }
            });
        },

        resetCloseWizard: function () {
            this.closeState = {
                step: 'summary',
                closeToken: '',
                drawerSessionId: 0,
                countedCash: 0,
                matched: false,
                attemptNumber: 0,
                handoverEnabled: false,
            };
            this.goCloseStep('summary');
            $('#pshCloseAmount').val('');
            $('#pshCloseMessage').addClass('psh-hidden').text('');
            $('#pshCloseVariance').addClass('psh-hidden');
            $('#pshCloseCountStep').removeClass('psh-hidden');
            $('[data-psh-close-final]').addClass('psh-hidden');
            // goCloseStep('summary') owns submit-count visibility — do not re-show it here.
        },

        goCloseStep: function (step) {
            this.closeState.step = step;
            $('.pos-close-shift-wizard-step').addClass('psh-hidden');
            $('#pshCloseStep-' + step).removeClass('psh-hidden');

            const $next = $('[data-psh-close-next]');
            const $submit = $('[data-psh-close-submit-count]');
            const $final = $('[data-psh-close-final]');
            const $back = $('[data-psh-close-back]');

            if (step === 'summary') {
                $next.removeClass('psh-hidden');
                $submit.addClass('psh-hidden');
                $final.addClass('psh-hidden');
                $back.addClass('psh-hidden');
            } else {
                $next.addClass('psh-hidden');
                $submit.removeClass('psh-hidden');
                // Hide back once a count attempt has been submitted (sticky attempts).
                if ((this.closeState.attemptNumber || 0) > 0) {
                    $back.addClass('psh-hidden');
                } else {
                    $back.removeClass('psh-hidden');
                }
            }

            if (step === 'count') {
                $.getJSON('do/do_begin_shift_close_count.php').done(function (response) {
                    if (response && response.success && response.data) {
                        wizard.closeState.handoverEnabled = true;
                        wizard.closeState.closeToken = response.data.close_token || '';
                        wizard.closeState.drawerSessionId = response.data.drawer_session_id || 0;
                        wizard.closeState.attemptNumber = Number(response.data.attempt_number || 0);
                        if (wizard.closeState.attemptNumber > 0) {
                            $('#pshCloseAttemptLabel').text(
                                'محاولة ' + wizard.closeState.attemptNumber + ' من ' + (response.data.max_attempts || 2)
                            );
                            $('[data-psh-close-back]').addClass('psh-hidden');
                        }
                    }
                });
                setTimeout(function () {
                    $('#pshCloseAmount').trigger('focus');
                }, 150);
            }
        },

        submitCloseCount: function () {
            const self = this;
            const amount = ($('#pshCloseAmount').val() || '').trim();
            const $message = $('#pshCloseMessage');
            const $btn = $('[data-psh-close-submit-count]');
            const scope = 'pos.shift.submit_close_count';

            if (amount === '') {
                $message.removeClass('psh-hidden').addClass('is-info').text('الرجاء إدخال المبلغ');
                return;
            }

            $btn.prop('disabled', true);
            $message.addClass('psh-hidden');

            $.ajax({
                url: 'do/do_submit_shift_close_count.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    counted_amount: amount,
                    idempotency_key: this.getIdempotencyKey(scope),
                    csrf_token: window.POSMAIN_SHIFT_CLOSE_COUNT_CSRF_TOKEN || '',
                },
            }).done(function (response) {
                if (!response || !response.success) {
                    $btn.prop('disabled', false);
                    const err = (response && response.error) || 'تعذر التحقق من العد';
                    if (err === 'CLOSE_EXPECTED_DRIFTED') {
                        self.clearIdempotencyKey(scope);
                        $message.removeClass('psh-hidden').addClass('is-warn').text('تغيّر الرصيد — الرجاء إعادة العد من البداية');
                        self.resetCloseWizard();
                        return;
                    }
                    $message.removeClass('psh-hidden').addClass('is-warn').text(err);
                    return;
                }

                const data = response.data || {};
                self.closeState.attemptNumber = data.attempt_number || 0;
                self.closeState.countedCash = Number(data.counted_cash || amount);
                self.closeState.matched = !!data.matched;
                self.closeState.closeToken = data.close_token || self.closeState.closeToken;

                if (data.status === 'recount') {
                    self.clearIdempotencyKey(scope);
                    $('#pshCloseAttemptLabel').text('محاولة ' + (data.attempt_number || 1) + ' من ' + (data.max_attempts || 2));
                    $message.removeClass('psh-hidden is-info is-success').addClass('is-warn').text(data.message || 'الرجاء إعادة العد بعناية');
                    $('#pshCloseAmount').val('').focus();
                    $('[data-psh-close-back]').addClass('psh-hidden');
                    $btn.prop('disabled', false);
                    return;
                }

                self.clearIdempotencyKey(scope);

                // Matched or max-attempt variance: auto-close immediately.
                // Never show over/short while the shift is still open.
                self.closeState.matched = data.status === 'ready_to_close' && !!data.matched;
                if (data.status === 'close_with_variance') {
                    self.closeState.matched = false;
                }
                $('[data-psh-close-back]').addClass('psh-hidden');
                $btn.prop('disabled', true);
                self.finalizeClose();
            }).fail(function () {
                $btn.prop('disabled', false);
                $message.removeClass('psh-hidden').addClass('is-warn').text('خطأ في الاتصال');
            });
        },

        finalizeClose: function () {
            if (typeof window.posShiftExpenseClosePayload !== 'function') {
                return;
            }

            const expensePayload = window.posShiftExpenseClosePayload();
            const notes = $('#shift_notes').val() || '';
            const counted = this.closeState.countedCash || ($('#pshCloseAmount').val() || 0);

            if (window.posmainMarkShiftClosing) {
                window.posmainMarkShiftClosing();
            } else {
                try {
                    sessionStorage.setItem('pos_shift_closed', '1');
                    sessionStorage.setItem('pos_locked', '1');
                } catch (e) {
                    // ignore
                }
            }

            const form = $('<form>', { method: 'POST', action: 'close_shift.php' });
            form.append($('<input>', { type: 'hidden', name: 'expenses', value: expensePayload.expenses || 0 }));
            form.append($('<input>', { type: 'hidden', name: 'exp_notes', value: expensePayload.exp_notes || '' }));
            form.append($('<input>', { type: 'hidden', name: 'cash', value: counted }));
            form.append($('<input>', { type: 'hidden', name: 'fund_after', value: counted }));
            form.append($('<input>', { type: 'hidden', name: 'counted_cash', value: counted }));
            form.append($('<input>', { type: 'hidden', name: 'notes', value: notes }));
            form.append($('<input>', { type: 'hidden', name: 'close_token', value: this.closeState.closeToken || '' }));
            form.append($('<input>', { type: 'hidden', name: 'matched', value: this.closeState.matched ? '1' : '0' }));
            form.append($('<input>', { type: 'hidden', name: 'drawer_session_id', value: this.closeState.drawerSessionId || 0 }));
            form.append($('<input>', { type: 'hidden', name: 'idempotency_key', value: this.getIdempotencyKey('pos.shift.close') }));
            if (window.POSMAIN_SHIFT_CSRF_TOKEN) {
                form.append($('<input>', { type: 'hidden', name: 'csrf_token', value: window.POSMAIN_SHIFT_CSRF_TOKEN }));
            }

            $('body').append(form);
            form.submit();
        },
    };

    window.PosShiftCountWizard = wizard;

    $(function () {
        wizard.initOpenOverlay();
        wizard.initCloseModal();
    });
})(window, window.jQuery);
