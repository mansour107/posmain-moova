/**
 * Shared PIN pad controller — same UI/behavior as first-login (auto-submit on 4 digits).
 */
(function (window) {
  'use strict';

  function digitFromKeyboardEvent(e) {
    if (e.key >= '0' && e.key <= '9') return e.key;
    if (e.code && e.code.indexOf('Numpad') === 0) {
      var n = e.code.replace('Numpad', '');
      if (/^\d$/.test(n)) return n;
    }
    return null;
  }

  function mapError(code, retryAfter) {
    var map = {
      PIN_INVALID: 'الرمز غير صحيح',
      MANAGER_PIN_INVALID: 'الرمز غير صحيح',
      MANAGER_PIN_MISMATCH: 'يجب إدخال رمزك أنت لتأكيد العملية',
      MANAGER_PERMISSION_DENIED: 'غير مصرح لهذا الرمز بهذا الإجراء',
      MANAGER_OVERRIDE_DENIED: 'تعذر اعتماد الرمز',
      PIN_TERMINAL_FROZEN: 'تم إيقاف المحاولات مؤقتاً. حاول لاحقاً',
      PIN_USER_LOCKED: 'تم قفل الرمز مؤقتاً. حاول لاحقاً',
      MANAGER_PIN_LOCKED: 'تم قفل الرمز مؤقتاً. حاول لاحقاً',
      PIN_RATE_LIMITED: 'محاولات كثيرة. انتظر قليلاً',
      PIN_RETRY_LATER: 'محاولات كثيرة. انتظر قليلاً',
      PIN_BLACKLISTED: 'هذا الرمز ضعيف وغير مسموح',
      PIN_FORMAT_INVALID: 'الرمز يجب أن يكون 4 أرقام',
      PIN_ALREADY_IN_USE: 'هذا الرمز مستخدم بالفعل',
      PIN_CONFIRM_MISMATCH: 'الرمزان غير متطابقين',
      CURRENT_PIN_INVALID: 'الرمز الحالي غير صحيح',
      PIN_UNCHANGED: 'اختر رمزاً جديداً مختلفاً',
      CSRF_INVALID: 'انتهت صلاحية الصفحة. حدّث وأعد المحاولة',
      SERVER_ERROR: 'تعذر الاتصال. حاول مرة أخرى',
      APPROVER_LIMIT_EXCEEDED: 'لا توجد صلاحية كافية لهذا الإجراء',
      OVERRIDE_INPUT_REQUIRED: 'أدخل رمزاً صالحاً'
    };
    var base = map[code] || (code && String(code).indexOf(' ') >= 0 ? String(code) : 'تعذر إتمام العملية');
    if (retryAfter && retryAfter > 0) {
      return base + ' (' + retryAfter + ' ث)';
    }
    return base;
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function buildPadMarkup(id, title, subtitle, roleHint) {
    var keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'مسح', '0', 'دخول'];
    var keyHtml = keys.map(function (key) {
      var cls = 'ppm-key';
      if (key === 'مسح') cls += ' action';
      else if (key === 'دخول') cls += ' enter';
      var label = key === 'دخول' ? 'تأكيد الرمز' : (key === 'مسح' ? 'مسح' : key);
      return '<button type="button" class="' + cls + '" data-key="' + key + '" aria-label="' + label + '">' + key + '</button>';
    }).join('');
    var safeTitle = escapeHtml(title);
    var safeSubtitle = escapeHtml(subtitle || '');
    var safeRoleHint = escapeHtml(roleHint || '');

    return (
      '<div class="ppm-shell" id="' + id + '" data-digits="4" data-mode="login" role="group" aria-label="' + safeTitle + '">' +
        '<div class="ppm-card">' +
          '<h1 class="ppm-title">' + safeTitle + '</h1>' +
          (safeSubtitle ? '<p class="ppm-sub">' + safeSubtitle + '</p>' : '') +
          (safeRoleHint ? '<p class="ppm-role-hint">' + safeRoleHint + '</p>' : '') +
          '<div class="ppm-error is-hidden" id="' + id + 'Error" role="alert" aria-live="polite"></div>' +
          '<div class="ppm-dots" id="' + id + 'Dots" dir="ltr" aria-hidden="true">' +
            '<span class="ppm-dot" data-idx="0"></span>' +
            '<span class="ppm-dot" data-idx="1"></span>' +
            '<span class="ppm-dot" data-idx="2"></span>' +
            '<span class="ppm-dot" data-idx="3"></span>' +
          '</div>' +
          '<span class="visually-hidden" id="' + id + 'Status" aria-live="polite"></span>' +
          '<div class="ppm-grid" id="' + id + 'Grid">' + keyHtml + '</div>' +
          '<form id="' + id + 'Form" method="post" action="" autocomplete="off" class="is-hidden"></form>' +
        '</div>' +
      '</div>'
    );
  }

  function initPinPad(rootOrId, options) {
    var root = typeof rootOrId === 'string' ? document.getElementById(rootOrId) : rootOrId;
    if (!root || root.getAttribute('data-ppm-ready') === '1') return null;
    root.setAttribute('data-ppm-ready', '1');

    var opts = options || {};
    var digits = parseInt(root.getAttribute('data-digits') || '4', 10) || 4;
    var endpoint = root.getAttribute('data-endpoint') || opts.endpoint || '';
    var dots = root.querySelectorAll('.ppm-dot');
    var errorEl = document.getElementById(root.id + 'Error');
    var statusEl = document.getElementById(root.id + 'Status');
    var grid = document.getElementById(root.id + 'Grid');
    var pin = '';
    var busy = false;
    var destroyed = false;
    var cooldownTimer = null;

    function setError(msg) {
      if (!errorEl) return;
      if (!msg) {
        errorEl.textContent = '';
        errorEl.classList.add('is-hidden');
        return;
      }
      errorEl.textContent = msg;
      errorEl.classList.remove('is-hidden');
    }

    function renderDots() {
      for (var i = 0; i < dots.length; i++) {
        if (i < pin.length) dots[i].classList.add('filled');
        else dots[i].classList.remove('filled');
      }
      if (statusEl) statusEl.textContent = pin.length + ' من ' + digits;
    }

    function startCooldown(seconds) {
      if (cooldownTimer) clearInterval(cooldownTimer);
      var left = Math.max(1, parseInt(seconds, 10) || 0);
      if (left < 1) return;
      busy = true;
      root.classList.add('ppm-busy');
      setError(mapError('PIN_RATE_LIMITED', left));
      cooldownTimer = setInterval(function () {
        left -= 1;
        if (left <= 0) {
          clearInterval(cooldownTimer);
          cooldownTimer = null;
          busy = false;
          root.classList.remove('ppm-busy');
          setError('');
          return;
        }
        setError(mapError('PIN_RATE_LIMITED', left));
      }, 1000);
    }

    function submitPin() {
      if (destroyed || busy || pin.length !== digits) return;
      if (typeof opts.onSubmit === 'function') {
        busy = true;
        root.classList.add('ppm-busy');
        setError('');
        Promise.resolve(opts.onSubmit(pin)).then(function (res) {
          if (destroyed) return;
          busy = false;
          root.classList.remove('ppm-busy');
          if (!res || res.ok === false) {
            var code = (res && res.code) || 'PIN_INVALID';
            var retryAfter = (res && (res.retry_after || res.cooldown_seconds)) || 0;
            if (retryAfter || code === 'PIN_RATE_LIMITED' || code === 'PIN_TERMINAL_FROZEN' || code === 'PIN_USER_LOCKED' || code === 'MANAGER_PIN_LOCKED' || code === 'PIN_RETRY_LATER') {
              startCooldown(retryAfter || 30);
            } else {
              setError(mapError(code, retryAfter));
            }
            pin = '';
            renderDots();
            return;
          }
          if (typeof opts.onSuccess === 'function') opts.onSuccess(res);
          if (res.close === true && typeof opts.onClose === 'function') opts.onClose(res);
        }).catch(function () {
          if (destroyed) return;
          busy = false;
          root.classList.remove('ppm-busy');
          setError(mapError('SERVER_ERROR'));
          pin = '';
          renderDots();
        });
        return;
      }

      if (!endpoint) return;
      busy = true;
      root.classList.add('ppm-busy');
      setError('');
      var body = new FormData();
      body.append('pin', pin);
      var csrfInput = root.querySelector('input[name="csrf_token"]');
      if (csrfInput) body.append('csrf_token', csrfInput.value);
      if (opts.extraFields) {
        Object.keys(opts.extraFields).forEach(function (k) {
          body.append(k, opts.extraFields[k]);
        });
      }

      // Local PIN auth must always attempt the request. Browser WAN status is not
      // local Docker/LAN reachability — sync/Moova own that gate.
      fetch(endpoint, {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (r) {
        return r.json().then(function (j) { return { ok: r.ok, status: r.status, j: j }; });
      }).then(function (res) {
        if (destroyed) return;
        busy = false;
        root.classList.remove('ppm-busy');
        if (res.j && res.j.success) {
          if (typeof opts.onSuccess === 'function') opts.onSuccess(res.j);
          else if (res.j.redirect) window.location.href = res.j.redirect;
          else window.location.reload();
          return;
        }
        var code = (res.j && (res.j.code || res.j.error)) || 'PIN_INVALID';
        if (res.j && res.j.redirect && (code === 'REGISTER_UNPAIRED' || code === 'PERMISSION_DENIED')) {
          window.location.href = res.j.redirect;
          return;
        }
        var retryAfter = (res.j && (res.j.retry_after || res.j.cooldown_seconds)) || 0;
        if (res.status === 429 || code === 'PIN_TERMINAL_FROZEN' || code === 'PIN_USER_LOCKED' || code === 'PIN_RATE_LIMITED' || code === 'PIN_RETRY_LATER' || code === 'MANAGER_PIN_LOCKED') {
          startCooldown(retryAfter || 30);
        } else {
          setError(mapError(code, retryAfter));
        }
        pin = '';
        renderDots();
      }).catch(function () {
        if (destroyed) return;
        busy = false;
        root.classList.remove('ppm-busy');
        setError(mapError('SERVER_ERROR'));
        pin = '';
        renderDots();
      });
    }

    function pushKey(key) {
      if (destroyed || busy) return;
      if (key === 'مسح') {
        pin = '';
        setError('');
        renderDots();
        return;
      }
      if (key === 'دخول') {
        submitPin();
        return;
      }
      if (/^\d$/.test(key) && pin.length < digits) {
        pin += key;
        setError('');
        renderDots();
        if (pin.length === digits && opts.autoSubmit !== false) {
          submitPin();
        }
      }
    }

    function onGridClick(e) {
      var btn = e.target.closest('[data-key]');
      if (!btn) return;
      pushKey(btn.getAttribute('data-key'));
    }

    function onKeyDown(e) {
      if (destroyed || !document.body.contains(root)) return;
      // Ignore when another modal pad is active above this one.
      var activeModal = document.getElementById('posPinPadModal');
      if (activeModal && !activeModal.contains(root) && root.id !== 'mainPinPad') return;
      if (e.key === 'Enter') { e.preventDefault(); pushKey('دخول'); return; }
      if (e.key === 'Escape' && typeof opts.onCancel === 'function') {
        e.preventDefault();
        opts.onCancel();
        return;
      }
      if (e.key === 'Backspace' || e.key === 'Delete') { e.preventDefault(); pushKey('مسح'); return; }
      var d = digitFromKeyboardEvent(e);
      if (d) { e.preventDefault(); pushKey(d); }
    }

    if (grid) grid.addEventListener('click', onGridClick);
    window.addEventListener('keydown', onKeyDown);

    if (opts.initialError) setError(String(opts.initialError));
    renderDots();

    return {
      reset: function () { pin = ''; setError(''); renderDots(); },
      setError: setError,
      getPin: function () { return pin; },
      destroy: function () {
        if (destroyed) return;
        destroyed = true;
        if (cooldownTimer) clearInterval(cooldownTimer);
        if (grid) grid.removeEventListener('click', onGridClick);
        window.removeEventListener('keydown', onKeyDown);
        root.removeAttribute('data-ppm-ready');
      }
    };
  }

  /**
   * Modal wrapper around the exact first-login ppm pad.
   * options: title, subtitle/message, roleHint, initialError, onSubmit(pin)->Promise,
   *          onCancel, cancelLabel, autoSubmit (default true)
   */
  function openModal(options) {
    options = options || {};
    var existing = document.getElementById('posPinPadModal');
    if (existing) existing.remove();

    var title = options.title || 'أدخل الرمز';
    var subtitle = options.subtitle || options.message || 'أدخل رمز الدخول المكوّن من 4 أرقام';
    var roleHint = options.roleHint || '';
    var padId = 'posmainModalPinPad';

    var overlay = document.createElement('div');
    overlay.id = 'posPinPadModal';
    overlay.className = 'ppm-modal';
    overlay.setAttribute('dir', 'rtl');
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.innerHTML =
      '<div class="ppm-modal__backdrop" data-pin-cancel></div>' +
      '<div class="ppm-modal__dialog">' +
        buildPadMarkup(padId, title, subtitle, roleHint) +
        '<button type="button" class="ppm-modal__cancel" data-pin-cancel>' +
          (options.cancelLabel || 'إلغاء') +
        '</button>' +
      '</div>';

    document.body.appendChild(overlay);

    var api = null;
    var closed = false;

    function close() {
      if (closed) return;
      closed = true;
      if (api && typeof api.destroy === 'function') api.destroy();
      if (overlay.parentNode) overlay.remove();
    }

    function cancel() {
      close();
      if (typeof options.onCancel === 'function') options.onCancel();
    }

    overlay.querySelectorAll('[data-pin-cancel]').forEach(function (el) {
      el.addEventListener('click', cancel);
    });

    api = initPinPad(padId, {
      autoSubmit: options.autoSubmit !== false,
      initialError: options.initialError || '',
      onCancel: cancel,
      onClose: function () { close(); },
      onSubmit: function (pin) {
        if (typeof options.onSubmit !== 'function') {
          return { ok: true, close: true, pin: pin };
        }
        return Promise.resolve(options.onSubmit(pin)).then(function (res) {
          if (!res) return { ok: false, code: 'PIN_INVALID' };
          return res;
        });
      }
    });

    return {
      close: close,
      setError: api ? api.setError : function () {},
      reset: api ? api.reset : function () {}
    };
  }

  window.PosmainPinPad = {
    init: initPinPad,
    openModal: openModal,
    mapError: mapError,
    digitFromKeyboardEvent: digitFromKeyboardEvent
  };
})(window);
