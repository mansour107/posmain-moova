/**
 * Shared PIN pad controller for main login / change PIN / overrides.
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

    var cooldownTimer = null;

    function mapError(code, retryAfter) {
      var map = {
        PIN_INVALID: 'الرمز غير صحيح',
        PIN_TERMINAL_FROZEN: 'تم إيقاف المحاولات مؤقتاً. حاول لاحقاً',
        PIN_USER_LOCKED: 'تم قفل الرمز مؤقتاً. حاول لاحقاً',
        PIN_RATE_LIMITED: 'محاولات كثيرة. انتظر قليلاً',
        PIN_RETRY_LATER: 'محاولات كثيرة. انتظر قليلاً',
        PIN_BLACKLISTED: 'هذا الرمز ضعيف وغير مسموح',
        PIN_FORMAT_INVALID: 'الرمز يجب أن يكون 4 أرقام',
        PIN_ALREADY_IN_USE: 'هذا الرمز مستخدم بالفعل',
        PIN_CONFIRM_MISMATCH: 'الرمزان غير متطابقين',
        CURRENT_PIN_INVALID: 'الرمز الحالي غير صحيح',
        PIN_UNCHANGED: 'اختر رمزاً جديداً مختلفاً',
        CSRF_INVALID: 'انتهت صلاحية الصفحة. حدّث وأعد المحاولة',
        OFFLINE: 'لا يوجد اتصال. تحقق من الشبكة ثم أعد المحاولة',
        SERVER_ERROR: 'تعذر الاتصال. حاول مرة أخرى'
      };
      var base = map[code] || 'تعذر إتمام العملية';
      if (retryAfter && retryAfter > 0) {
        return base + ' (' + retryAfter + ' ث)';
      }
      return base;
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
      if (busy || pin.length !== digits) return;
      if (typeof opts.onSubmit === 'function') {
        busy = true;
        root.classList.add('ppm-busy');
        Promise.resolve(opts.onSubmit(pin)).then(function (res) {
          busy = false;
          root.classList.remove('ppm-busy');
          if (res && res.ok === false) {
            setError(mapError(res.code || 'PIN_INVALID'));
            pin = '';
            renderDots();
          }
        }).catch(function () {
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

      if (typeof navigator !== 'undefined' && navigator.onLine === false) {
        busy = false;
        root.classList.remove('ppm-busy');
        setError(mapError('OFFLINE'));
        pin = '';
        renderDots();
        return;
      }

      fetch(endpoint, {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (r) {
        return r.json().then(function (j) { return { ok: r.ok, status: r.status, j: j }; });
      }).then(function (res) {
        busy = false;
        root.classList.remove('ppm-busy');
        if (res.j && res.j.success) {
          if (typeof opts.onSuccess === 'function') opts.onSuccess(res.j);
          else if (res.j.redirect) window.location.href = res.j.redirect;
          else window.location.reload();
          return;
        }
        var code = (res.j && (res.j.code || res.j.error)) || 'PIN_INVALID';
        var retryAfter = (res.j && (res.j.retry_after || res.j.cooldown_seconds)) || 0;
        if (res.status === 429 || code === 'PIN_TERMINAL_FROZEN' || code === 'PIN_USER_LOCKED' || code === 'PIN_RATE_LIMITED' || code === 'PIN_RETRY_LATER') {
          startCooldown(retryAfter || 30);
        } else {
          setError(mapError(code, retryAfter));
        }
        pin = '';
        renderDots();
      }).catch(function () {
        busy = false;
        root.classList.remove('ppm-busy');
        setError(mapError(typeof navigator !== 'undefined' && navigator.onLine === false ? 'OFFLINE' : 'SERVER_ERROR'));
        pin = '';
        renderDots();
      });
    }

    function pushKey(key) {
      if (busy) return;
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

    if (grid) {
      grid.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-key]');
        if (!btn) return;
        pushKey(btn.getAttribute('data-key'));
      });
    }

    window.addEventListener('keydown', function (e) {
      if (!document.body.contains(root)) return;
      if (e.key === 'Enter') { e.preventDefault(); pushKey('دخول'); return; }
      if (e.key === 'Backspace' || e.key === 'Delete') { e.preventDefault(); pushKey('مسح'); return; }
      var d = digitFromKeyboardEvent(e);
      if (d) { e.preventDefault(); pushKey(d); }
    });

    renderDots();
    return {
      reset: function () { pin = ''; setError(''); renderDots(); },
      setError: setError,
      getPin: function () { return pin; }
    };
  }

  window.PosmainPinPad = { init: initPinPad, digitFromKeyboardEvent: digitFromKeyboardEvent };
})(window);
