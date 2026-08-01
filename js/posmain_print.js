(function () {
  'use strict';

  var config = window.POSMAIN_PRINT_CONFIG || {};
  var nativePrint = typeof window.print === 'function' ? window.print.bind(window) : null;
  var activePromise = null;
  var pendingRequestKey = null;

  function randomKey() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return 'browser:' + window.crypto.randomUUID();
    }
    var random = Math.random().toString(36).slice(2);
    return 'browser:' + Date.now() + ':' + random;
  }

  function inferContext(override) {
    var explicit = override || window.POSMAIN_PRINT_CONTEXT || {};
    var body = document.body || {};
    var dataset = body.dataset || {};
    var path = String(window.location && window.location.pathname || '').toLowerCase();
    var inferredType = 'document';
    if (/barcode|br2538/.test(path)) {
      inferredType = 'label';
    } else if (/report|summary|drawer_session|closed_session|print_sales|daily_sales|shift_sales/.test(path)) {
      inferredType = 'report';
    }
    var type = explicit.jobType || dataset.printJobType || inferredType;
    var orderId = Number(explicit.orderId || dataset.printOrderId || 0);
    var title = explicit.title || document.title || 'POSMAIN';
    var rootSelector = explicit.contentSelector || dataset.printContentSelector || '';
    var root = rootSelector ? document.querySelector(rootSelector) : document.body;

    return {
      job_type: type,
      order_id: orderId > 0 ? orderId : null,
      title: title,
      content_text: root ? String(root.innerText || '').trim() : ''
    };
  }

  function showStatus(message, kind) {
    var existing = document.getElementById('posmain-print-status');
    var status = existing || document.createElement('div');
    status.id = 'posmain-print-status';
    status.setAttribute('role', kind === 'error' ? 'alert' : 'status');
    status.style.cssText = [
      'position:fixed',
      'z-index:2147483647',
      'left:20px',
      'bottom:20px',
      'max-width:360px',
      'padding:12px 16px',
      'border-radius:12px',
      'font:600 14px/1.5 system-ui,sans-serif',
      'box-shadow:0 12px 36px rgba(15,23,42,.2)',
      'background:' + (kind === 'error' ? '#fff1f2' : '#ecfdf5'),
      'color:' + (kind === 'error' ? '#9f1239' : '#065f46'),
      'border:1px solid ' + (kind === 'error' ? '#fecdd3' : '#a7f3d0')
    ].join(';');
    status.textContent = message;
    if (!existing && document.body) {
      document.body.appendChild(status);
    }
    window.setTimeout(function () {
      if (status.parentNode) status.parentNode.removeChild(status);
    }, kind === 'error' ? 8000 : 3500);
  }

  function dispatch(contextOverride) {
    if (config.mode !== 'silent') {
      if (nativePrint) nativePrint();
      return Promise.resolve({ status: 'legacy' });
    }
    if (activePromise) return activePromise;

    var context = inferContext(contextOverride);
    if (!pendingRequestKey) pendingRequestKey = randomKey();
    context.request_key = pendingRequestKey;

    activePromise = window.fetch(config.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-Token': config.csrfToken || ''
      },
      body: JSON.stringify(context)
    }).then(function (response) {
      return response.json().catch(function () {
        throw new Error('لم يصل رد واضح من خدمة الطباعة. حاول مرة أخرى.');
      }).then(function (payload) {
        if (!response.ok || payload.success !== true) {
          throw new Error(payload.message || 'تعذر إرسال الطباعة. تحقق من حالة الطابعة ثم حاول مرة أخرى.');
        }
        return payload;
      });
    }).then(function (payload) {
      if (payload.result && payload.result.status === 'attention_required') {
        showStatus('تعذر التسليم إلى طابعة. راجع شاشة الطابعات قبل إعادة المحاولة.', 'error');
      } else if (payload.result && payload.result.status === 'queued') {
        showStatus('تم حفظ مهمة الطباعة وستُعاد المحاولة تلقائياً.', 'success');
      } else {
        showStatus('تم إرسال الطباعة بنجاح.', 'success');
      }
      pendingRequestKey = null;
      return payload;
    }).catch(function (error) {
      // Keep the same request key after a lost/failed HTTP response. A user
      // retry therefore resolves to the original durable jobs, not duplicates.
      showStatus(error.message || 'تعذر تأكيد الطباعة. تحقق من حالة الطابعة ثم حاول مرة أخرى.', 'error');
      throw error;
    }).finally(function () {
      activePromise = null;
    });

    return activePromise;
  }

  window.posmainPrint = {
    mode: config.mode === 'silent' ? 'silent' : 'legacy',
    dispatch: dispatch,
    nativePrint: nativePrint
  };

  if (config.mode === 'silent') {
    window.print = function () {
      return dispatch();
    };
  }
}());
