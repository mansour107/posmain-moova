(function () {
  'use strict';

  const root = document.getElementById('teamHubRoot');
  if (!root) return;

  const configEl = document.getElementById('teamHubConfig');
  const config = configEl ? JSON.parse(configEl.textContent || '{}') : {};
  const csrfUsers = config.csrfUsers || '';
  const csrfRoles = config.csrfRoles || '';
  const canUsers = !!config.canUsers;
  const canRoles = !!config.canRoles;

  let staff = config.staff || [];
  let roles = config.roles || [];
  let activeTab = config.initialTab || 'staff';
  let panelMode = null;
  let panelEntityId = null;
  let generatedPin = '';
  let selectedRoleId = config.defaultRoleId || 0;

  const els = {
    tabStaff: document.getElementById('tabStaff'),
    tabRoles: document.getElementById('tabRoles'),
    sectionStaff: document.getElementById('sectionStaff'),
    sectionRoles: document.getElementById('sectionRoles'),
    staffGrid: document.getElementById('staffGrid'),
    rolesGrid: document.getElementById('rolesGrid'),
    staffSearch: document.getElementById('staffSearch'),
    rolesSearch: document.getElementById('rolesSearch'),
    backdrop: document.getElementById('panelBackdrop'),
    panel: document.getElementById('teamPanel'),
    panelTitle: document.getElementById('panelTitle'),
    panelBody: document.getElementById('panelBody'),
    panelFooter: document.getElementById('panelFooter'),
    toast: document.getElementById('teamToast'),
    toastMsg: document.getElementById('teamToastMsg'),
    toastPin: document.getElementById('teamToastPin'),
    statRoles: document.getElementById('statRoles'),
    statStaff: document.getElementById('statStaff'),
  };

  function api(action, options) {
    const opts = options || {};
    const method = opts.method || 'GET';
    const url = '/ajax/team_hub.php?action=' + encodeURIComponent(action);
    const init = { method, credentials: 'same-origin', headers: {} };
    if (method === 'POST') {
      const body = new FormData();
      Object.keys(opts.body || {}).forEach(function (k) {
        const v = opts.body[k];
        if (Array.isArray(v)) {
          v.forEach(function (item) { body.append(k + '[]', item); });
        } else if (v !== undefined && v !== null) {
          body.append(k, String(v));
        }
      });
      body.append('action', action);
      init.body = body;
      init.headers['X-CSRF-Token'] = opts.csrf || csrfUsers;
    }
    return fetch(url, init).then(function (r) {
      return r.json().then(function (data) {
        if (!r.ok) throw data;
        return data;
      });
    });
  }

  function showToast(message, pin) {
    els.toastMsg.textContent = message;
    if (pin) {
      els.toastPin.textContent = pin;
      els.toastPin.classList.remove('team-hub-hidden');
    } else {
      els.toastPin.classList.add('team-hub-hidden');
    }
    els.toast.classList.add('is-visible');
    setTimeout(function () { els.toast.classList.remove('is-visible'); }, 6000);
  }

  const confirmEl = document.getElementById('teamHubConfirm');
  const confirmTitle = document.getElementById('teamConfirmTitle');
  const confirmMsg = document.getElementById('teamConfirmMsg');
  const confirmOk = document.getElementById('teamConfirmOk');
  const confirmCancel = document.getElementById('teamConfirmCancel');
  const confirmBackdrop = document.getElementById('teamConfirmBackdrop');
  let confirmResolver = null;

  function lifecycleErrorMessage(code) {
    const map = {
      LAST_ADMIN_BLOCKED: 'لا يمكن إيقاف أو حذف آخر مدير في النظام',
      DRAWER_SESSION_OPEN: 'أغلق وردية هذا الموظف أولاً',
      USER_DELETE_BLOCKED: 'تعذر الحذف — للموظف سجل مرتبط بالنظام. يمكنك الإيقاف المؤقت فقط',
      INVALID_USER: 'لا يمكن تنفيذ هذا الإجراء على هذا الحساب',
      FORBIDDEN: 'ليس لديك صلاحية',
    };
    return map[code] || code || 'تعذر التنفيذ';
  }

  function formatDrawerOpenedAt(value) {
    if (!value) return '';
    const parsed = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(parsed.getTime())) return String(value);
    try {
      return parsed.toLocaleString('ar-EG', { dateStyle: 'medium', timeStyle: 'short' });
    } catch (e) {
      return String(value);
    }
  }

  function drawerSessionAlertMessage(sessions) {
    const list = Array.isArray(sessions) ? sessions : [];
    let message = 'هذا الموظف لديه وردية نقاط بيع مفتوحة. أغلق الوردية من نقطة البيع أو من شاشة إغلاق الوردية ثم حاول مرة أخرى.';
    if (list.length === 1 && list[0].opened_at) {
      message += '\n\nبدأت الوردية: ' + formatDrawerOpenedAt(list[0].opened_at);
    } else if (list.length > 1) {
      message += '\n\nعدد الورديات المفتوحة: ' + list.length;
    }
    return message;
  }

  function showAlert(opts) {
    if (!confirmEl || !confirmOk) {
      window.alert((opts.title ? opts.title + '\n\n' : '') + (opts.message || ''));
      return Promise.resolve();
    }
    closeConfirm();
    confirmTitle.textContent = opts.title || 'تنبيه';
    confirmMsg.textContent = opts.message || '';
    confirmOk.textContent = opts.confirmLabel || 'حسناً';
    confirmOk.classList.remove('team-hub-btn-danger');
    if (confirmCancel) confirmCancel.classList.add('team-hub-hidden');
    confirmEl.classList.add('is-open');
    confirmEl.setAttribute('aria-hidden', 'false');
    return new Promise(function (resolve) {
      confirmResolver = function () { resolve(true); };
    });
  }

  function showLifecycleError(err) {
    const code = err && err.code;
    if (code === 'DRAWER_SESSION_OPEN') {
      return showAlert({
        title: 'لا يمكن الإيقاف الآن',
        message: drawerSessionAlertMessage(err.drawer_sessions),
      });
    }
    const titles = {
      LAST_ADMIN_BLOCKED: 'لا يمكن الحذف',
      USER_DELETE_BLOCKED: 'لا يمكن الحذف نهائياً',
      FORBIDDEN: 'صلاحية غير كافية',
    };
    return showAlert({
      title: titles[code] || 'تعذر التنفيذ',
      message: lifecycleErrorMessage(code),
    });
  }

  function fetchStaffLifecycleBlockers(staffId) {
    return fetch('/ajax/team_hub.php?action=staff_lifecycle_blockers&user_id=' + encodeURIComponent(staffId), {
      credentials: 'same-origin',
    }).then(function (r) {
      return r.json();
    }).then(function (data) {
      if (!data || !data.success) return { blockers: [] };
      return data;
    }).catch(function () {
      return { blockers: [] };
    });
  }

  function closeConfirm() {
    if (!confirmEl) return;
    confirmEl.classList.remove('is-open');
    confirmEl.setAttribute('aria-hidden', 'true');
    confirmResolver = null;
  }

  function showConfirm(opts) {
    if (!confirmEl || !confirmOk || !confirmCancel) {
      return Promise.resolve(window.confirm((opts.title || '') + '\n' + (opts.message || '')));
    }
    closeConfirm();
    confirmTitle.textContent = opts.title || '';
    confirmMsg.textContent = opts.message || '';
    confirmOk.textContent = opts.confirmLabel || 'تأكيد';
    confirmOk.classList.toggle('team-hub-btn-danger', !!opts.danger);
    confirmCancel.classList.remove('team-hub-hidden');
    confirmEl.classList.add('is-open');
    confirmEl.setAttribute('aria-hidden', 'false');
    return new Promise(function (resolve) {
      confirmResolver = resolve;
    });
  }

  if (confirmCancel) {
    confirmCancel.onclick = function () {
      if (confirmResolver) confirmResolver(false);
      closeConfirm();
    };
  }
  if (confirmOk) {
    confirmOk.onclick = function () {
      if (confirmResolver) confirmResolver(true);
      closeConfirm();
    };
  }
  if (confirmBackdrop) {
    confirmBackdrop.onclick = function () {
      if (confirmResolver) confirmResolver(false);
      closeConfirm();
    };
  }

  function openPanel(title, bodyHtml, footerHtml) {
    els.panelTitle.textContent = title;
    els.panelBody.innerHTML = bodyHtml;
    els.panelFooter.innerHTML = footerHtml || '';
    els.backdrop.classList.add('is-open');
    els.panel.classList.add('is-open');
    els.panel.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  }

  function closePanel() {
    els.backdrop.classList.remove('is-open');
    els.panel.classList.remove('is-open');
    els.panel.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    panelMode = null;
    panelEntityId = null;
    clearPanelUrlParams();
  }

  function getUrlParams() {
    return new URLSearchParams(window.location.search);
  }

  function replaceUrlParams(mutator) {
    const params = getUrlParams();
    mutator(params);
    const query = params.toString();
    const next = query ? '?' + query : window.location.pathname;
    history.replaceState(null, '', next);
  }

  function clearPanelUrlParams() {
    replaceUrlParams(function (params) {
      params.delete('user');
      params.delete('role');
      params.delete('section');
      params.delete('panel');
    });
  }

  function reloadTeamHubPage() {
    clearPanelUrlParams();
    window.location.reload();
  }

  function setTab(tab) {
    activeTab = tab;
    const isStaff = tab === 'staff';
    if (els.tabStaff) els.tabStaff.classList.toggle('is-active', isStaff);
    if (els.tabRoles) els.tabRoles.classList.toggle('is-active', !isStaff);
    if (els.sectionStaff) els.sectionStaff.classList.toggle('team-hub-hidden', !isStaff);
    if (els.sectionRoles) els.sectionRoles.classList.toggle('team-hub-hidden', isStaff);
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('tab', tab);
    history.replaceState(null, '', '?' + urlParams.toString());
  }

  function renderStaff() {
    const q = (els.staffSearch.value || '').trim().toLowerCase();
    const filtered = staff.filter(function (s) {
      if (!q) return true;
      return (s.display_name + ' ' + s.uname + ' ' + s.role_name).toLowerCase().indexOf(q) >= 0;
    });
    let html = '';
    filtered.forEach(function (s) {
      html += '<article class="team-hub-card team-hub-card--person' + (s.is_deactivated ? ' is-deactivated' : '') + '" data-staff-id="' + s.id + '">';
      html += '<span class="team-hub-card-stripe" style="background:' + esc(s.role_color) + '"></span>';
      html += roleIconHtml(s.role_key, s.role_color);
      html += '<div class="team-hub-card-name">' + esc(s.display_name) + '</div>';
      html += '<span class="team-hub-chip" style="border-color:' + esc(s.role_color) + ';color:' + esc(s.role_color) + '">' + esc(s.role_name) + '</span>';
      html += '<div class="team-hub-pin-dot ' + (s.pin_locked ? 'locked' : (s.has_pin ? 'ok' : 'none')) + '">';
      if (s.pin_locked) {
        html += '🔒 PIN مقفل';
      } else if (s.pin_display) {
        html += '<span class="team-hub-pin-code">' + esc(s.pin_display) + '</span>';
      } else if (s.has_pin) {
        html += 'PIN ····';
      } else {
        html += 'بدون PIN';
      }
      html += '</div></article>';
    });
    if (canUsers) {
      html += '<button type="button" class="team-hub-card team-hub-card-add" id="addStaffCard">+ إضافة موظف</button>';
    }
    els.staffGrid.innerHTML = html;
    if (els.statStaff) els.statStaff.textContent = staff.filter(function (s) { return !s.is_deactivated; }).length;
  }

  function renderRoles() {
    const q = (els.rolesSearch.value || '').trim().toLowerCase();
    const filtered = roles.filter(function (r) {
      if (!q) return true;
      return (r.name + ' ' + r.info).toLowerCase().indexOf(q) >= 0;
    });
    let html = '';
    filtered.forEach(function (r) {
      html += '<article class="team-hub-card team-hub-card--role" data-role-id="' + r.id + '">';
      html += '<span class="team-hub-card-stripe" style="background:' + esc(r.color) + '"></span>';
      if (r.is_owner) html += '<span class="team-hub-lock-badge" title="مقفل"><i class="fas fa-lock" aria-hidden="true"></i></span>';
      html += roleIconHtml(r.role_key, r.color);
      html += '<div class="team-hub-card-name">' + esc(r.name) + '</div>';
      html += '<div class="team-hub-meta">' + r.staff_count + ' موظف · ' + r.permission_count + ' صلاحية</div>';
      if (r.info) html += '<div class="team-hub-meta">' + esc(r.info) + '</div>';
      html += '</article>';
    });
    if (canRoles) {
      html += '<button type="button" class="team-hub-card team-hub-card-add" id="addRoleCard">+ دور جديد</button>';
    }
    els.rolesGrid.innerHTML = html;
    if (els.statRoles) els.statRoles.textContent = roles.length;
  }

  function esc(str) {
    return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  }

  function normalizeRoleKey(roleKey) {
    return String(roleKey || '').toLowerCase().trim();
  }

  function roleIconFa(roleKey) {
    const map = {
      owner: 'fa-crown',
      manager: 'fa-user-shield',
      cashier: 'fa-cash-register',
      waiter: 'fa-concierge-bell',
      kitchen: 'fa-fire',
    };
    return map[normalizeRoleKey(roleKey)] || 'fa-id-badge';
  }

  function roleIconHtml(roleKey, color, size) {
    const key = normalizeRoleKey(roleKey);
    const modifier = key || 'custom';
    const sizeClass = size === 'sm' ? ' team-hub-role-icon--sm' : '';
    const style = color ? ' style="--role-icon-color:' + esc(color) + '"' : '';
    return '<div class="team-hub-role-icon team-hub-role-icon--' + esc(modifier) + sizeClass + '"' + style + '>'
      + '<i class="fas ' + roleIconFa(key) + '" aria-hidden="true"></i></div>';
  }

  function rolePickHtml(selectedId) {
    let html = '<div class="team-hub-role-scroll-wrap" id="rolePickScrollWrap">'
      + '<button type="button" class="team-hub-role-scroll-btn team-hub-role-scroll-btn--prev" aria-label="أدوار سابقة" tabindex="-1">'
      + '<i class="fas fa-chevron-right" aria-hidden="true"></i></button>'
      + '<div class="team-hub-role-row" id="rolePickRow">';
    roles.forEach(function (r) {
      const sel = Number(selectedId) === Number(r.id) ? ' is-selected' : '';
      const pressed = Number(selectedId) === Number(r.id) ? ' aria-pressed="true"' : ' aria-pressed="false"';
      html += '<button type="button" class="team-hub-role-pick' + sel + '" data-role-id="' + r.id + '"'
        + pressed + ' style="--role-pick-color:' + esc(r.color) + '">'
        + '<span class="team-hub-role-pick-check" aria-hidden="true"><i class="fas fa-check"></i></span>'
        + roleIconHtml(r.role_key, r.color, 'sm')
        + '<strong>' + esc(r.name) + '</strong></button>';
    });
    if (canRoles) {
      html += '<button type="button" class="team-hub-role-pick is-add" id="inlineNewRoleBtn">+ دور</button>';
    }
    html += '</div>'
      + '<button type="button" class="team-hub-role-scroll-btn team-hub-role-scroll-btn--next" aria-label="أدوار تالية" tabindex="-1">'
      + '<i class="fas fa-chevron-left" aria-hidden="true"></i></button></div>';
    return html;
  }

  function setRolePickSelected(roleId) {
    selectedRoleId = Number(roleId) || 0;
    document.querySelectorAll('#rolePickRow .team-hub-role-pick[data-role-id]').forEach(function (btn) {
      const isSel = Number(btn.dataset.roleId) === selectedRoleId;
      btn.classList.toggle('is-selected', isSel);
      btn.setAttribute('aria-pressed', isSel ? 'true' : 'false');
    });
  }

  function wireRolePickScroll() {
    const wrap = document.getElementById('rolePickScrollWrap');
    const row = document.getElementById('rolePickRow');
    if (!wrap || !row) return;

    const prev = wrap.querySelector('.team-hub-role-scroll-btn--prev');
    const next = wrap.querySelector('.team-hub-role-scroll-btn--next');
    const isRtl = function () {
      return getComputedStyle(row).direction === 'rtl';
    };

    function rolePickItems() {
      return row.querySelectorAll('.team-hub-role-pick, #inlineNewRoleBtn');
    }

    function scrollOverflow() {
      const items = rolePickItems();
      if (!items.length) {
        return { scrollable: false, canPrev: false, canNext: false };
      }

      const maxScroll = row.scrollWidth - row.clientWidth;
      const rowRect = row.getBoundingClientRect();
      const first = items[0].getBoundingClientRect();
      const last = items[items.length - 1].getBoundingClientRect();
      const spanOverflow = last.right - first.left > rowRect.width + 4;
      const scrollable = maxScroll > 4 || spanOverflow;

      if (!scrollable) {
        return { scrollable: false, canPrev: false, canNext: false };
      }

      const rtl = isRtl();
      const margin = 2;
      let canPrev;
      let canNext;
      if (rtl) {
        canPrev = first.right > rowRect.right + margin;
        canNext = last.left < rowRect.left - margin;
      } else {
        canPrev = first.left < rowRect.left - margin;
        canNext = last.right > rowRect.right + margin;
      }

      if (maxScroll > 4) {
        let pos = row.scrollLeft;
        if (rtl) {
          pos = row.scrollLeft <= 0 ? -row.scrollLeft : maxScroll - row.scrollLeft;
        }
        canPrev = pos > 4;
        canNext = pos < maxScroll - 4;
      }

      return { scrollable: true, canPrev: canPrev, canNext: canNext };
    }

    function scrollRoleRow(direction) {
      const items = rolePickItems();
      if (!items.length) return;

      const rowRect = row.getBoundingClientRect();
      const rtl = isRtl();
      const margin = 4;
      let target = null;

      if (direction === 'next') {
        for (let i = 0; i < items.length; i++) {
          const rect = items[i].getBoundingClientRect();
          const clipped = rtl
            ? rect.left < rowRect.left - margin
            : rect.right > rowRect.right + margin;
          if (clipped) {
            target = items[i];
            break;
          }
        }
        if (!target) target = items[items.length - 1];
      } else {
        for (let i = items.length - 1; i >= 0; i--) {
          const rect = items[i].getBoundingClientRect();
          const clipped = rtl
            ? rect.right > rowRect.right + margin
            : rect.left < rowRect.left - margin;
          if (clipped) {
            target = items[i];
            break;
          }
        }
        if (!target) target = items[0];
      }

      target.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' });
    }

    function updateScrollAffordance() {
      const state = scrollOverflow();
      wrap.classList.toggle('is-scrollable', state.scrollable);
      wrap.classList.toggle('can-scroll-prev', state.canPrev);
      wrap.classList.toggle('can-scroll-next', state.canNext);
      if (prev) {
        prev.disabled = !state.canPrev;
        prev.tabIndex = state.canPrev ? 0 : -1;
      }
      if (next) {
        next.disabled = !state.canNext;
        next.tabIndex = state.canNext ? 0 : -1;
      }
    }

    if (prev) prev.onclick = function () { scrollRoleRow('prev'); };
    if (next) next.onclick = function () { scrollRoleRow('next'); };

    row.addEventListener('scroll', updateScrollAffordance, { passive: true });
    window.addEventListener('resize', updateScrollAffordance);
    if (typeof ResizeObserver !== 'undefined') {
      new ResizeObserver(updateScrollAffordance).observe(row);
      new ResizeObserver(updateScrollAffordance).observe(wrap);
    }
    requestAnimationFrame(function () {
      updateScrollAffordance();
      requestAnimationFrame(updateScrollAffordance);
    });
    setTimeout(updateScrollAffordance, 120);
  }

  function fetchPin(excludeId) {
    return fetch('/ajax/team_hub.php?action=generate_pin&exclude_user_id=' + encodeURIComponent(excludeId || 0), {
      credentials: 'same-origin',
    }).then(function (r) {
      return r.json();
    }).catch(function () {
      return { pin: String(Math.floor(1000 + Math.random() * 9000)) };
    });
  }

  let panelStaffSection = 'basics';

  function pinPanelInitialText(s, isEdit) {
    if (s && s.pin_display) {
      return String(s.pin_display);
    }
    if (isEdit && s && s.has_pin) {
      return '····';
    }
    return '—';
  }

  function staffBasicsHtml(s, isEdit) {
    return ''
      + '<div class="team-hub-field"><label class="team-hub-label">الاسم</label>'
      + '<input class="team-hub-input" id="staffDisplayName" value="' + esc(s ? s.display_name : '') + '" placeholder="مثال: أحمد"></div>'
      + '<div class="team-hub-field"><label class="team-hub-label">اسم المستخدم</label>'
      + '<input class="team-hub-input" id="staffUname" value="' + esc(s ? s.uname : '') + '" placeholder="يُولَّد تلقائياً"></div>'
      + '<div class="team-hub-field"><label class="team-hub-label">الهاتف</label>'
      + '<input class="team-hub-input" id="staffPhone" value="' + esc(s ? s.phone : '') + '"></div>'
      + '<div class="team-hub-field"><label class="team-hub-label">الدور</label>' + rolePickHtml(s ? s.role_id : selectedRoleId) + '</div>'
      + '<div class="team-hub-field"><label class="team-hub-label">رمز PIN</label>'
      + '<div class="team-hub-pin-display" id="pinDisplay">' + esc(pinPanelInitialText(s, isEdit)) + '</div>'
      + '<div class="team-hub-pin-actions">'
      + '<button type="button" class="team-hub-btn" id="regenPinBtn">توليد رمز جديد</button>'
      + '<button type="button" class="team-hub-btn" id="manualPinBtn">تعديل يدوي</button>'
      + '</div><p class="team-hub-hint">' + (isEdit
        ? (s && s.has_pin
          ? 'لتغيير PIN: ولّد رمزاً جديداً أو عدّل يدوياً ثم اضغط حفظ'
          : 'لا يوجد PIN — تم توليد رمز جديد، اضغط حفظ لتفعيله')
        : 'مطلوب — يُولَّد تلقائياً ويُعرض مرة واحدة بعد الحفظ') + '</p></div>';
  }

  const STAFF_PERM_SECTION = 'permissions';

  function staffInnerTabsHtml(active) {
    return '<div class="team-hub-inner-tabs" id="staffInnerTabs">'
      + '<button type="button" class="team-hub-inner-tab' + (active === 'basics' ? ' is-active' : '') + '" data-staff-tab="basics">الأساسيات</button>'
      + '<button type="button" class="team-hub-inner-tab' + (active === STAFF_PERM_SECTION ? ' is-active' : '') + '" data-staff-tab="' + STAFF_PERM_SECTION + '">صلاحيات الموظف</button>'
      + '</div>';
  }

  function isStaffPermSection(section) {
    return section === STAFF_PERM_SECTION || section === 'overrides';
  }

  function staffPermSectionKey(section) {
    return isStaffPermSection(section) ? STAFF_PERM_SECTION : 'basics';
  }

  function setStaffPanelSection(section) {
    panelStaffSection = staffPermSectionKey(section);
    const basics = document.getElementById('staffTabBasics');
    const overrides = document.getElementById('staffTabOverrides');
    if (basics) basics.classList.toggle('team-hub-hidden', panelStaffSection !== 'basics');
    if (overrides) overrides.classList.toggle('team-hub-hidden', panelStaffSection !== STAFF_PERM_SECTION);
    document.querySelectorAll('#staffInnerTabs .team-hub-inner-tab').forEach(function (btn) {
      btn.classList.toggle('is-active', btn.dataset.staffTab === panelStaffSection);
    });
    const saveBasics = document.getElementById('staffSaveBtn');
    const saveOverrides = document.getElementById('staffPermSaveBtn');
    if (saveBasics) saveBasics.classList.toggle('team-hub-hidden', panelStaffSection !== 'basics');
    if (saveOverrides) saveOverrides.classList.toggle('team-hub-hidden', panelStaffSection !== STAFF_PERM_SECTION);
  }

  function staffPermToggleHtml(perm) {
    const customized = perm.customized ? '<span class="team-hub-meta team-hub-perm-custom">مخصّص</span>' : '';
    return '<div class="team-hub-toggle-row" data-perm-row="' + esc(perm.key) + '">'
      + '<span class="team-hub-perm-label">' + esc(perm.label) + customized + '</span>'
      + '<label class="team-hub-switch">'
      + '<input type="checkbox" data-perm="' + esc(perm.key) + '" data-from-role="' + (perm.from_role ? '1' : '0') + '"' + (perm.enabled ? ' checked' : '') + '>'
      + '<span></span></label></div>';
  }

  function collectPermissionsPayload() {
    const grants = [];
    const denies = [];
    document.querySelectorAll('#staffTabOverrides input[data-perm]').forEach(function (cb) {
      const key = cb.dataset.perm;
      if (!key) return;
      const fromRole = cb.dataset.fromRole === '1';
      const desired = cb.checked;
      if (desired && !fromRole) grants.push(key);
      if (!desired && fromRole) denies.push(key);
    });
    return {
      permission_mode: (grants.length || denies.length) ? 'role_with_overrides' : 'role_only',
      grant: grants,
      deny: denies,
    };
  }

  function loadStaffPermissionsTab(staffId) {
    const container = document.getElementById('staffTabOverrides');
    if (!container) return;
    container.innerHTML = '<p class="team-hub-meta">جاري التحميل…</p>';
    fetch('/ajax/team_hub.php?action=user_permissions&user_id=' + encodeURIComponent(staffId), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) throw data;
        const perm = data.permissions;
        let html = '<div class="team-hub-perm-intro">'
          + '<p class="team-hub-meta">' + (perm.enabled_count || 0) + ' صلاحية مفعّلة حالياً</p>'
          + '<p class="team-hub-hint">المفاتيح تعكس ما يملكه الموظف فعلياً. يمكنك تشغيلها أو إيقافها لتعديل صلاحياته فوق دور «'
          + esc(perm.role_name) + '».</p></div>';
        (perm.groups || []).forEach(function (g, gi) {
          const groupEnabled = (g.permissions || []).filter(function (p) { return p.enabled; }).length;
          html += '<details class="team-hub-accordion"' + (gi === 0 ? ' open' : '') + '><summary><span>' + esc(g.label) + '</span>'
            + '<span class="team-hub-meta">' + groupEnabled + '/' + (g.permissions || []).length + '</span></summary><div class="team-hub-accordion-body">';
          (g.permissions || []).forEach(function (p) {
            html += staffPermToggleHtml(p);
          });
          html += '</div></details>';
        });
        container.innerHTML = html;
      })
      .catch(function () {
        container.innerHTML = '<p class="team-hub-meta">تعذر تحميل الصلاحيات.</p>';
      });
  }

  function openStaffPanel(staffId, opts) {
    opts = opts || {};
    panelMode = staffId ? 'edit_staff' : 'create_staff';
    panelEntityId = staffId || null;
    panelStaffSection = staffPermSectionKey(opts.section || 'basics');
    const isEdit = !!staffId;
    const title = isEdit ? 'تعديل موظف' : 'موظف جديد';
    const s = isEdit ? staff.find(function (x) { return x.id === staffId; }) : null;

    let body;
    if (isEdit) {
      body = staffInnerTabsHtml(panelStaffSection)
        + '<div id="staffTabBasics"' + (panelStaffSection !== 'basics' ? ' class="team-hub-hidden"' : '') + '>'
        + staffBasicsHtml(s, isEdit) + '</div>'
        + '<div id="staffTabOverrides"' + (panelStaffSection === 'basics' ? ' class="team-hub-hidden"' : '') + '>'
        + '<p class="team-hub-meta">جاري التحميل…</p></div>';
    } else {
      body = staffBasicsHtml(s, isEdit);
    }

    const footer = ''
      + (isEdit ? '<button type="button" class="team-hub-btn team-hub-btn-ghost team-hub-btn-danger" id="staffDeleteBtn">حذف نهائي</button>' : '')
      + (isEdit ? '<button type="button" class="team-hub-btn team-hub-btn-ghost" id="staffDeactivateBtn">' + (s && s.is_deactivated ? 'استئناف' : 'إيقاف مؤقت') + '</button>' : '')
      + (isEdit && s && s.pin_locked ? '<button type="button" class="team-hub-btn team-hub-btn-ghost" id="staffUnlockBtn">فتح PIN</button>' : '')
      + '<button type="button" class="team-hub-btn team-hub-btn-ghost" id="panelCancelBtn">إلغاء</button>'
      + '<button type="button" class="team-hub-btn team-hub-btn-primary' + (isEdit && panelStaffSection === STAFF_PERM_SECTION ? ' team-hub-hidden' : '') + '" id="staffSaveBtn">حفظ</button>'
      + (isEdit ? '<button type="button" class="team-hub-btn team-hub-btn-primary' + (panelStaffSection === 'basics' ? ' team-hub-hidden' : '') + '" id="staffPermSaveBtn">حفظ الصلاحيات</button>' : '');

    openPanel(title, body, footer);
    selectedRoleId = s ? s.role_id : (selectedRoleId || (roles.find(function (r) { return r.role_key === 'cashier'; }) || roles[0] || {}).id);
    setRolePickSelected(selectedRoleId);

    if (isEdit) {
      document.querySelectorAll('#staffInnerTabs .team-hub-inner-tab').forEach(function (btn) {
        btn.onclick = function () {
          const tab = btn.dataset.staffTab;
          setStaffPanelSection(tab);
          if (tab === STAFF_PERM_SECTION && !document.querySelector('#staffTabOverrides input[data-perm]')) {
            loadStaffPermissionsTab(staffId);
          }
        };
      });
      if (panelStaffSection === STAFF_PERM_SECTION) {
        loadStaffPermissionsTab(staffId);
      }
    }

    generatedPin = '';
    const pinEl = document.getElementById('pinDisplay');
    if (isEdit && s && s.pin_display) {
      if (pinEl) pinEl.textContent = String(s.pin_display);
    } else if (isEdit && s && s.has_pin) {
      if (pinEl) pinEl.textContent = '····';
    } else {
      fetchPin(isEdit ? (panelEntityId || 0) : 0).then(function (data) {
        generatedPin = data.pin || '';
        if (pinEl) pinEl.textContent = generatedPin || '—';
      });
    }

    wireStaffPanel(isEdit);
    wireRolePickScroll();
  }

  function runStaffLifecycleAction(staffId, action) {
    const s = staff.find(function (x) { return x.id === staffId; });
    const isPaused = !!(s && s.is_deactivated);
    const name = (s && s.display_name) || 'هذا الموظف';
    const isReactivate = action === 'reactivate_staff';

    function confirmAndRun() {
      showConfirm({
        title: isReactivate ? 'استئناف الموظف؟' : 'إيقاف مؤقت؟',
        message: isReactivate
          ? 'سيعود «' + name + '» للعمل ويستطيع تسجيل الدخول مجدداً.'
          : 'سيتم إيقاف «' + name + '» مؤقتاً. يمكنك استئنافه لاحقاً من قائمة الموقوفين.',
        confirmLabel: isReactivate ? 'استئناف' : 'إيقاف مؤقت',
      }).then(function (ok) {
        if (!ok) return;
        api(action, { method: 'POST', body: { user_id: staffId, csrf_token: csrfUsers }, csrf: csrfUsers })
          .then(function () { reloadTeamHubPage(); })
          .catch(function (err) { showLifecycleError(err); });
      });
    }

    if (isReactivate) {
      confirmAndRun();
      return;
    }

    fetchStaffLifecycleBlockers(staffId).then(function (data) {
      const blockers = data.blockers || [];
      const drawerBlock = blockers.find(function (b) { return b.code === 'DRAWER_SESSION_OPEN'; });
      if (drawerBlock) {
        showLifecycleError({
          code: 'DRAWER_SESSION_OPEN',
          drawer_sessions: drawerBlock.drawer_sessions || [],
        });
        return;
      }
      confirmAndRun();
    });
  }

  function runStaffDeleteAction(staffId) {
    const s = staff.find(function (x) { return x.id === staffId; });
    const name = (s && s.display_name) || 'هذا الموظف';

    function confirmAndDelete() {
      showConfirm({
        title: 'حذف نهائي؟',
        message: 'سيتم حذف «' + name + '» نهائياً ولا يمكن التراجع. يُفضّل الإيقاف المؤقت إن أردت الاحتفاظ بالسجل.',
        confirmLabel: 'حذف نهائي',
        danger: true,
      }).then(function (ok) {
        if (!ok) return;
        api('delete_staff', { method: 'POST', body: { user_id: staffId, csrf_token: csrfUsers }, csrf: csrfUsers })
          .then(function () {
            staff = staff.filter(function (x) { return x.id !== staffId; });
            closePanel();
            renderStaff();
            showToast('تم حذف الموظف');
          })
          .catch(function (err) { showLifecycleError(err); });
      });
    }

    fetchStaffLifecycleBlockers(staffId).then(function (data) {
      const blockers = data.blockers || [];
      const drawerBlock = blockers.find(function (b) { return b.code === 'DRAWER_SESSION_OPEN'; });
      if (drawerBlock) {
        showLifecycleError({
          code: 'DRAWER_SESSION_OPEN',
          drawer_sessions: drawerBlock.drawer_sessions || [],
        });
        return;
      }
      confirmAndDelete();
    });
  }

  function wireStaffPanel(isEdit) {
    document.getElementById('panelCancelBtn').onclick = closePanel;
    document.getElementById('regenPinBtn').onclick = function () {
      fetchPin(panelEntityId || 0).then(function (data) {
        generatedPin = data.pin || '';
        document.getElementById('pinDisplay').textContent = generatedPin;
      });
    };
    document.getElementById('manualPinBtn').onclick = function () {
      const v = prompt('أدخل PIN من 4-6 أرقام');
      if (v) {
        generatedPin = v.replace(/\D/g, '');
        document.getElementById('pinDisplay').textContent = generatedPin;
      }
    };
    document.querySelectorAll('#rolePickRow .team-hub-role-pick[data-role-id]').forEach(function (btn) {
      btn.onclick = function () {
        setRolePickSelected(Number(btn.dataset.roleId));
      };
    });
    const inlineRole = document.getElementById('inlineNewRoleBtn');
    if (inlineRole) inlineRole.onclick = openQuickRolePanel;

    document.getElementById('staffSaveBtn').onclick = function () {
      const displayName = document.getElementById('staffDisplayName').value.trim();
      let uname = document.getElementById('staffUname').value.trim();
      if (!uname && displayName) {
        uname = displayName.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '').replace(/^_+|_+$/g, '');
        if (!uname || !/^[a-z]/.test(uname)) {
          uname = 'user_' + Date.now().toString().slice(-6) + '_' + Math.random().toString(36).slice(2, 6);
        }
      }
      if (!isEdit && (!generatedPin || generatedPin.length < 4)) {
        alert('رمز PIN مطلوب — اضغط توليد رمز جديد');
        return;
      }
      const body = {
        csrf_token: csrfUsers,
        display_name: displayName,
        uname: uname,
        phone: document.getElementById('staffPhone').value.trim(),
        userrole: selectedRoleId,
      };
      if (!isEdit) {
        body.pin = generatedPin;
        body.generate_pin = '1';
      } else if (generatedPin && generatedPin.length >= 4) {
        body.pin = generatedPin;
      }
      const action = isEdit ? 'update_staff' : 'create_staff';
      if (isEdit) body.user_id = panelEntityId;
      api(action, { method: 'POST', body: body, csrf: csrfUsers }).then(function (res) {
        if (res.staff) {
          const idx = staff.findIndex(function (x) { return x.id === res.staff.id; });
          const card = Object.assign({}, res.staff, {
            role_color: (roles.find(function (r) { return r.id === res.staff.role_id; }) || {}).color || '#9ca3af',
            role_name: res.staff.role_name || '—',
            role_key: res.staff.role_key || (roles.find(function (r) { return r.id === res.staff.role_id; }) || {}).role_key || '',
            pin_display: res.pin || res.staff.pin_display || null,
            has_pin: res.pin ? true : res.staff.has_pin,
          });
          if (idx >= 0) staff[idx] = card; else staff.unshift(card);
        } else if (!isEdit && res.user_id) {
          location.reload();
          return;
        }
        renderStaff();
        closePanel();
        if (res.pin) showToast(isEdit ? 'تم حفظ PIN الجديد — انسخه الآن' : 'تم الحفظ — انسخ PIN الآن', res.pin);
        else showToast('تم الحفظ بنجاح');
      }).catch(function (err) {
        alert((err && (err.message || err.code)) || 'تعذر الحفظ');
      });
    };

    const deact = document.getElementById('staffDeactivateBtn');
    if (deact) {
      deact.onclick = function () {
        const s = staff.find(function (x) { return x.id === panelEntityId; });
        runStaffLifecycleAction(panelEntityId, s && s.is_deactivated ? 'reactivate_staff' : 'deactivate_staff');
      };
    }
    const deleteBtn = document.getElementById('staffDeleteBtn');
    if (deleteBtn) {
      deleteBtn.onclick = function () {
        runStaffDeleteAction(panelEntityId);
      };
    }
    const unlock = document.getElementById('staffUnlockBtn');
    if (unlock) {
      unlock.onclick = function () {
        api('unlock_pin', { method: 'POST', body: { user_id: panelEntityId, csrf_token: csrfUsers }, csrf: csrfUsers }).then(function () {
          reloadTeamHubPage();
        });
      };
    }
    const permSave = document.getElementById('staffPermSaveBtn');
    if (permSave) {
      permSave.onclick = function () {
        const payload = collectPermissionsPayload();
        api('save_user_permissions', {
          method: 'POST',
          body: Object.assign({ user_id: panelEntityId, csrf_token: csrfUsers }, payload),
          csrf: csrfUsers,
        }).then(function () {
          showToast('تم حفظ الصلاحيات');
          loadStaffPermissionsTab(panelEntityId);
        }).catch(function (err) {
          alert((err && (err.message || err.code)) || 'تعذر الحفظ');
        });
      };
    }
  }

  function openQuickRolePanel() {
    const name = prompt('اسم الدور الجديد');
    if (!name) return;
    api('create_role', {
      method: 'POST',
      body: { rollname: name, clone_from: 'cashier', csrf_token: csrfRoles },
      csrf: csrfRoles,
    }).then(function (res) {
      if (res.role) {
        roles.push({
          id: res.role.id,
          name: res.role.name,
          color: res.role.color,
          role_key: '',
          staff_count: 0,
          permission_count: res.role.enabled_count,
        });
        selectedRoleId = res.role.id;
        openStaffPanel(panelEntityId);
      } else location.reload();
    });
  }

  function openRolePanel(roleId) {
    panelMode = roleId ? 'edit_role' : 'create_role';
    panelEntityId = roleId;
    if (!roleId) {
      const body = '<div class="team-hub-field"><label class="team-hub-label">اسم الدور</label><input class="team-hub-input" id="newRoleName"></div>'
        + '<div class="team-hub-field"><label class="team-hub-label">ابدأ من</label><div class="team-hub-template-pills" id="clonePills">'
        + '<button type="button" class="team-hub-template-pill" data-clone="cashier">كاشير</button>'
        + '<button type="button" class="team-hub-template-pill" data-clone="waiter">ويتر</button>'
        + '<button type="button" class="team-hub-template-pill is-active" data-clone="manager">مدير</button>'
        + '<button type="button" class="team-hub-template-pill" data-clone="empty">فارغ</button></div></div>';
      openPanel('دور جديد', body, '<button type="button" class="team-hub-btn team-hub-btn-ghost" id="panelCancelBtn">إلغاء</button><button type="button" class="team-hub-btn team-hub-btn-primary" id="roleCreateBtn">إنشاء</button>');
      let cloneFrom = 'manager';
      document.querySelectorAll('#clonePills .team-hub-template-pill').forEach(function (p) {
        p.onclick = function () {
          cloneFrom = p.dataset.clone;
          document.querySelectorAll('#clonePills .team-hub-template-pill').forEach(function (x) { x.classList.remove('is-active'); });
          p.classList.add('is-active');
        };
      });
      document.getElementById('panelCancelBtn').onclick = closePanel;
      document.getElementById('roleCreateBtn').onclick = function () {
        const rollname = document.getElementById('newRoleName').value.trim();
        if (!rollname) return alert('أدخل اسم الدور');
        api('create_role', { method: 'POST', body: { rollname: rollname, clone_from: cloneFrom, csrf_token: csrfRoles }, csrf: csrfRoles }).then(function () {
          location.reload();
        }).catch(function (e) { alert(e.code || 'فشل'); });
      };
      return;
    }

    fetch('/ajax/team_hub.php?action=role_detail&id=' + encodeURIComponent(roleId), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) throw data;
        const role = data.role;
        let body = '<div class="team-hub-meta" style="margin-bottom:1rem">' + role.enabled_count + ' صلاحية مفعّلة</div>';
        if (role.is_preset && !role.is_owner) {
          body += '<div class="team-hub-template-pills"><button type="button" class="team-hub-btn team-hub-btn-ghost" id="restorePresetBtn">استعادة الافتراضي</button></div>';
        }
        role.groups.forEach(function (g, gi) {
          body += '<details class="team-hub-accordion"' + (gi === 0 ? ' open' : '') + '><summary><span>' + esc(g.label) + '</span><span class="team-hub-meta">' + g.enabled_count + '/' + g.total_count + '</span></summary><div class="team-hub-accordion-body">';
          g.permissions.forEach(function (p) {
            const dis = role.is_readonly ? ' disabled' : '';
            body += '<div class="team-hub-toggle-row"><span>' + esc(p.label) + '</span>'
              + '<label class="team-hub-switch"><input type="checkbox" data-perm="' + esc(p.key) + '"' + (p.enabled ? ' checked' : '') + dis + '><span></span></label></div>';
            if (p.has_limit && p.enabled) {
              body += '<div class="team-hub-limit" data-limit-for="' + esc(p.key) + '"><span class="team-hub-meta">الحد</span><div class="team-hub-stepper">'
                + '<button type="button" data-step="-1">−</button><input type="number" class="team-hub-input" data-limit-val="' + esc(p.key) + '" value="' + (p.limit_value || 0) + '" min="0" step="0.5">'
                + '<button type="button" data-step="1">+</button></div>'
                + '<label class="team-hub-meta"><input type="checkbox" data-limit-unlimited="' + esc(p.key) + '"' + (p.is_unlimited ? ' checked' : '') + dis + '> غير محدود</label></div>';
            }
          });
          body += '</div></details>';
        });

        const footer = role.is_readonly
          ? '<button type="button" class="team-hub-btn team-hub-btn-ghost" id="panelCancelBtn">إغلاق</button>'
          : '<button type="button" class="team-hub-btn team-hub-btn-ghost" id="panelCancelBtn">إلغاء</button>'
            + (role.can_delete ? '<button type="button" class="team-hub-btn team-hub-btn-ghost" id="roleDeleteBtn">حذف الدور</button>' : '')
            + '<button type="button" class="team-hub-btn team-hub-btn-primary" id="roleSaveBtn">حفظ</button>';

        openPanel('صلاحيات: ' + role.name, body, footer);
        document.getElementById('panelCancelBtn').onclick = closePanel;

        const restore = document.getElementById('restorePresetBtn');
        if (restore) {
          restore.onclick = function () {
            if (!confirm('استعادة الإعدادات الافتراضية؟')) return;
            api('restore_role_preset', { method: 'POST', body: { role_id: roleId, role_key: role.role_key, csrf_token: csrfRoles }, csrf: csrfRoles }).then(function () {
              location.reload();
            });
          };
        }

        if (!role.is_readonly) {
          const deleteBtn = document.getElementById('roleDeleteBtn');
          if (deleteBtn) {
            deleteBtn.onclick = function () {
              if (!confirm('حذف هذا الدور؟')) return;
              api('delete_role', { method: 'POST', body: { role_id: roleId, csrf_token: csrfRoles }, csrf: csrfRoles })
                .then(function () { location.reload(); })
                .catch(function (e) {
                  alert(e.code === 'ROLE_HAS_STAFF' ? 'انقل الموظفين أولاً' : (e.code || 'تعذر الحذف'));
                });
            };
          }
          document.getElementById('roleSaveBtn').onclick = function () {
            const perms = [];
            els.panelBody.querySelectorAll('input[data-perm]:checked').forEach(function (cb) {
              perms.push(cb.dataset.perm);
            });
            const bodyData = { role_id: roleId, csrf_token: csrfRoles };
            perms.forEach(function (p, i) { bodyData['permissions[' + i + ']'] = p; });
            els.panelBody.querySelectorAll('[data-limit-val]').forEach(function (inp) {
              bodyData['limit_value[' + inp.dataset.limitVal + ']'] = inp.value;
            });
            els.panelBody.querySelectorAll('[data-limit-unlimited]').forEach(function (cb) {
              if (cb.checked) bodyData['limit_unlimited[' + cb.dataset.limitUnlimited + ']'] = '1';
            });
            const fd = new FormData();
            fd.append('action', 'save_role_permissions');
            fd.append('role_id', String(roleId));
            fd.append('csrf_token', csrfRoles);
            perms.forEach(function (p) { fd.append('permissions[]', p); });
            els.panelBody.querySelectorAll('[data-limit-val]').forEach(function (inp) {
              fd.append('limit_value[' + inp.dataset.limitVal + ']', inp.value);
            });
            els.panelBody.querySelectorAll('[data-limit-unlimited]:checked').forEach(function (cb) {
              fd.append('limit_unlimited[' + cb.dataset.limitUnlimited + ']', '1');
            });
            fetch('/ajax/team_hub.php?action=save_role_permissions', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrfRoles } })
              .then(function (r) { return r.json(); })
              .then(function (res) {
                if (!res.success) throw res;
                closePanel();
                showToast('تم حفظ صلاحيات الدور');
                location.reload();
              })
              .catch(function (e) { alert(e.code || 'فشل الحفظ'); });
          };
        }

        els.panelBody.querySelectorAll('.team-hub-stepper button').forEach(function (btn) {
          btn.onclick = function () {
            const input = btn.parentElement.querySelector('input');
            input.value = Math.max(0, Number(input.value) + Number(btn.dataset.step));
          };
        });
      });
  }

  if (els.tabStaff) els.tabStaff.onclick = function () { setTab('staff'); };
  if (els.tabRoles) els.tabRoles.onclick = function () { setTab('roles'); };
  if (els.staffSearch) els.staffSearch.oninput = renderStaff;
  if (els.rolesSearch) els.rolesSearch.oninput = renderRoles;
  if (els.backdrop) els.backdrop.onclick = closePanel;

  const btnAddStaff = document.getElementById('btnAddStaff');
  if (btnAddStaff) btnAddStaff.onclick = function () { openStaffPanel(null); };

  const btnAddRole = document.getElementById('btnAddRole');
  if (btnAddRole) btnAddRole.onclick = function () { openRolePanel(null); };

  if (els.staffGrid) {
    els.staffGrid.addEventListener('click', function (e) {
      const card = e.target.closest('[data-staff-id]');
      if (card) {
        openStaffPanel(Number(card.dataset.staffId));
      }
      if (e.target.id === 'addStaffCard') openStaffPanel(null);
    });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && els.panel.classList.contains('is-open')) {
      closePanel();
    }
  });

  if (els.rolesGrid) {
    els.rolesGrid.addEventListener('click', function (e) {
      const card = e.target.closest('[data-role-id]');
      if (card) openRolePanel(Number(card.dataset.roleId));
      if (e.target.id === 'addRoleCard') openRolePanel(null);
    });
  }

  const urlParams = getUrlParams();
  const pendingPanelOpen = {
    staffNew: urlParams.get('panel') === 'new' && canUsers,
    roleNew: urlParams.get('panel') === 'new_role' && canRoles,
    roleId: Number(urlParams.get('role') || 0),
    staffId: Number(urlParams.get('user') || 0),
    staffSection: isStaffPermSection(urlParams.get('section') || '') ? STAFF_PERM_SECTION : 'basics',
  };
  if (pendingPanelOpen.staffNew || pendingPanelOpen.roleNew || pendingPanelOpen.roleId > 0 || pendingPanelOpen.staffId > 0) {
    clearPanelUrlParams();
  }

  renderStaff();
  renderRoles();
  setTab(activeTab);

  if (config.pinReveal) {
    showToast('PIN لمرة واحدة — انسخه الآن', config.pinReveal);
  }

  if (pendingPanelOpen.staffNew) {
    setTimeout(function () { openStaffPanel(null); }, 100);
  }
  if (pendingPanelOpen.roleNew) {
    setTimeout(function () { openRolePanel(null); }, 100);
  }
  if (pendingPanelOpen.roleId > 0 && canRoles) {
    setTimeout(function () { openRolePanel(pendingPanelOpen.roleId); }, 150);
  }
  if (pendingPanelOpen.staffId > 0 && canUsers) {
    setTimeout(function () {
      openStaffPanel(pendingPanelOpen.staffId, { section: pendingPanelOpen.staffSection });
    }, 150);
  }

  window.teamHubClosePanel = closePanel;
})();
