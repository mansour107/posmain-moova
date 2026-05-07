(function posWidgetBootstrap() {
  const BUILD_VERSION = '__BUILD_VERSION__';
  const DEFAULT_POLL_INTERVAL_MS = 5000;
  const DEFAULT_HEARTBEAT_INTERVAL_MS = 20000;
  const TOAST_DURATION_MS = 3200;
  const HOST_ORDER_ACK_TIMEOUT_MS = 30000;
  const ORDER_NOTIFICATION_SOUND_URL = '/assets/new.wav';
  const NOTIFICATION_SOUND_INTERVAL_MS = 6000;
  const NOTIFICATION_SOUND_VOLUME = 1.0;
  const NOTIFICATION_MUTE_STORAGE_KEY = 'cofe.mute';
  const WIDGET_SOURCE = 'pos_iframe_widget';
  const LOCALE_MESSAGES = {
    en: {
      pendingApprovals: 'Pending approvals',
      waitingForOrders: 'Waiting for orders',
      allOrdersKicker: 'Pending approval queue',
      allOrdersTitle: 'All pending orders',
      detailKicker: 'Order review',
      detailTitle: 'Order details',
      notesKicker: 'Notes review',
      notesTitle: 'Customer notes',
      closeAllOrders: 'Close all orders',
      closeOrderDetails: 'Close order details',
      closeNotes: 'Close notes',
      missingDeviceToken: 'Missing device token.',
      failedStartWidget: 'Failed to start the approval widget.',
      failedFetchPending: 'Failed to fetch pending orders.',
      newOrderToast: 'New POS order received.',
      pendingOrdersNotice: 'There are pending POS orders.',
      muteNotifications: 'Mute notifications',
      unmuteNotifications: 'Unmute notifications',
      notesConfirmedToast: 'Notes confirmed.',
      failedConfirmNotes: 'Failed to confirm notes.',
      confirmNotesFirst: 'Confirm the notes first.',
      orderConfirmedToast: 'Order confirmed.',
      failedConfirmOrder: 'Failed to confirm the order.',
      orderDeclinedToast: 'Order declined.',
      failedDeclineOrder: 'Failed to decline the order.',
      missingHostOrderPayload: 'Order data is not ready yet. Please refresh and try again.',
      hostOrderAckTimeout: 'The POS did not confirm order creation in time.',
      syncing: 'syncing',
      live: 'live',
      retryNeeded: 'Retry needed',
      reviewNotes: 'Review notes',
      ready: 'Ready',
      table: 'Table',
      total: 'Total',
      confirm: 'Confirm',
      confirmOrder: 'Confirm order',
      noOrdersWaitingTitle: 'No orders waiting now',
      noOrdersWaitingCopy: 'The queue is empty. New POS approvals will appear here automatically.',
      noNotes: 'No notes',
      notesPending: 'Notes pending',
      notesConfirmedLabel: 'Notes confirmed',
      reviewNotesFirstCallout: 'Review and confirm the notes before accepting this order.',
      noNotesAttachedTitle: 'No notes attached',
      noNotesAttachedCopy: 'This order has no order-level or item-level notes.',
      itemNote: 'Item note',
      confirmNotes: 'Confirm notes',
      confirmNotesAndOrder: 'Confirm notes and place order',
      saving: 'Saving...',
      confirming: 'Confirming...',
      decline: 'Decline',
      declineOrder: 'Decline order',
      declineReasonKicker: 'Decline order',
      declineReasonTitle: 'Reason for decline',
      closeDeclineReason: 'Close decline reason',
      cancelDecline: 'Cancel',
      declineReasonLabel: 'Reason',
      declineReasonPlaceholder: 'Optional reason for the customer',
      declineReasonHint: 'Leave blank to decline without a reason.',
      submitDecline: 'Decline order',
      declining: 'Declining...',
      orderNote: 'Order note',
      deliveryNote: 'Delivery note',
      noItemsReceived: 'No items received.',
      noItems: 'No items',
      itemFallback: 'Item',
      justNow: 'Just now',
      counterOrder: 'Counter order',
      tableDisplay: ({ table }) => `Table ${table}`,
      showAll: ({ count }) => `Show all ${count}`,
      pendingOrdersMeta: ({ count, transport, isOne }) => `${count} pending ${isOne ? 'order' : 'orders'} · ${transport}`,
      singleItemSummary: ({ first, quantity }) => `${first} · ${quantity}`,
      moreSummary: ({ first, count }) => `${first} + ${count} more`,
    },
    ar: {
      pendingApprovals: 'الطلبات المعلّقة',
      waitingForOrders: 'بانتظار الطلبات',
      allOrdersKicker: 'قائمة الموافقات المعلّقة',
      allOrdersTitle: 'كل الطلبات المعلّقة',
      detailKicker: 'مراجعة الطلب',
      detailTitle: 'تفاصيل الطلب',
      notesKicker: 'مراجعة الملاحظات',
      notesTitle: 'ملاحظات العميل',
      closeAllOrders: 'إغلاق كل الطلبات',
      closeOrderDetails: 'إغلاق تفاصيل الطلب',
      closeNotes: 'إغلاق الملاحظات',
      missingDeviceToken: 'رمز الجهاز غير موجود.',
      failedStartWidget: 'تعذر تشغيل ويدجت الموافقة.',
      failedFetchPending: 'تعذر جلب الطلبات المعلّقة.',
      newOrderToast: 'وصل طلب جديد لنقاط البيع.',
      pendingOrdersNotice: 'توجد طلبات معلّقة لنقاط البيع.',
      muteNotifications: 'كتم صوت الإشعارات',
      unmuteNotifications: 'تشغيل صوت الإشعارات',
      notesConfirmedToast: 'تم تأكيد الملاحظات.',
      failedConfirmNotes: 'تعذر تأكيد الملاحظات.',
      confirmNotesFirst: 'يجب تأكيد الملاحظات أولاً.',
      orderConfirmedToast: 'تم تأكيد الطلب.',
      failedConfirmOrder: 'تعذر تأكيد الطلب.',
      orderDeclinedToast: 'تم رفض الطلب.',
      failedDeclineOrder: 'تعذر رفض الطلب.',
      missingHostOrderPayload: 'بيانات الطلب غير جاهزة بعد. حدّث الصفحة وحاول مرة أخرى.',
      hostOrderAckTimeout: 'لم يؤكد نظام نقاط البيع إنشاء الطلب في الوقت المحدد.',
      syncing: 'جارٍ التحديث',
      live: 'مباشر',
      retryNeeded: 'إعادة المحاولة',
      reviewNotes: 'مراجعة الملاحظات',
      ready: 'جاهز',
      table: 'الطاولة',
      total: 'الإجمالي',
      confirm: 'تأكيد',
      confirmOrder: 'تأكيد الطلب',
      noOrdersWaitingTitle: 'لا توجد طلبات بانتظار الموافقة',
      noOrdersWaitingCopy: 'القائمة فارغة الآن. ستظهر طلبات نقاط البيع الجديدة هنا تلقائياً.',
      noNotes: 'لا توجد ملاحظات',
      notesPending: 'ملاحظات بانتظار المراجعة',
      notesConfirmedLabel: 'تمت مراجعة الملاحظات',
      reviewNotesFirstCallout: 'راجع الملاحظات وأكدها قبل قبول هذا الطلب.',
      noNotesAttachedTitle: 'لا توجد ملاحظات',
      noNotesAttachedCopy: 'هذا الطلب لا يحتوي على ملاحظات عامة أو ملاحظات خاصة بالأصناف.',
      itemNote: 'ملاحظة الصنف',
      confirmNotes: 'تأكيد الملاحظات',
      confirmNotesAndOrder: 'تأكيد الملاحظات وطلب الأوردر',
      saving: 'جارٍ الحفظ...',
      confirming: 'جارٍ التأكيد...',
      decline: 'رفض',
      declineOrder: 'رفض الطلب',
      declineReasonKicker: 'رفض الطلب',
      declineReasonTitle: 'سبب الرفض',
      closeDeclineReason: 'إغلاق سبب الرفض',
      cancelDecline: 'إلغاء',
      declineReasonLabel: 'السبب',
      declineReasonPlaceholder: 'سبب اختياري للعميل',
      declineReasonHint: 'اتركه فارغاً لرفض الطلب بدون سبب.',
      submitDecline: 'رفض الطلب',
      declining: 'جارٍ الرفض...',
      orderNote: 'ملاحظة الطلب',
      deliveryNote: 'ملاحظة التوصيل',
      noItemsReceived: 'لم تصل أصناف لهذا الطلب.',
      noItems: 'لا توجد أصناف',
      itemFallback: 'صنف',
      justNow: 'الآن',
      counterOrder: 'طلب كاونتر',
      tableDisplay: ({ table }) => `طاولة ${table}`,
      showAll: ({ count }) => `عرض الكل ${count}`,
      pendingOrdersMeta: ({ count, transport }) => `${transport} · ${count} طلبات معلّقة`,
      singleItemSummary: ({ first, quantity }) => `${first} · ${quantity}`,
      moreSummary: ({ first, count }) => `${first} + ${count} أخرى`,
    },
  };

  const state = {
    deviceToken: null,
    parentOrigin: null,
    activeInitKey: null,
    locale: 'en',
    hostBellMode: false,
    navbarBellMode: false,
    bootstrap: null,
    device: null,
    drafts: [],
    commands: [],
    panelOpen: false,
    emptyStateOpen: false,
    detailDraftId: null,
    notesDraftId: null,
    declineDraftId: null,
    declineVia: 'overlay',
    allOrdersOpen: false,
    refreshPromise: null,
    pollTimer: null,
    heartbeatTimer: null,
    websocket: null,
    reconnectTimer: null,
    wsConnected: false,
    toastTimer: null,
    toastVisible: false,
    toastMessage: '',
    notificationAudio: null,
    notificationAudioUrl: null,
    soundLoopInterval: null,
    notificationMuted: readStoredMutePreference(),
    confirmingDraftIds: new Set(),
    confirmingNotesIds: new Set(),
    decliningDraftIds: new Set(),
    pendingHostOrderResults: new Map(),
    lastSignals: {
      visible: null,
      count: null,
      frame: null,
    },
  };

  const elements = {};

  document.addEventListener('DOMContentLoaded', () => {
    cacheElements();
    bindEvents();
    render();
    postToParent('cofe.widget.ready', { buildVersion: BUILD_VERSION });
  });

  function cacheElements() {
    elements.root = document.getElementById('pos-widget-root');
    elements.anchor = document.getElementById('pos-widget-anchor');
    elements.bell = document.getElementById('pos-widget-bell');
    elements.bellLabel = document.getElementById('pos-widget-bell-label');
    elements.bellMeta = document.getElementById('pos-widget-bell-meta');
    elements.badge = document.getElementById('pos-widget-badge');
    elements.soundToggle = document.getElementById('pos-widget-sound-toggle');
    elements.soundLabel = document.getElementById('pos-widget-sound-label');
    elements.stack = document.getElementById('pos-widget-stack');
    elements.toast = document.getElementById('pos-widget-toast');
    elements.allOrdersModal = document.getElementById('pos-widget-all-orders-modal');
    elements.allOrdersContent = document.getElementById('pos-widget-all-orders-content');
    elements.allOrdersKicker = document.getElementById('pw-all-orders-kicker');
    elements.allOrdersTitle = document.getElementById('pw-all-orders-title');
    elements.detailModal = document.getElementById('pos-widget-detail-modal');
    elements.detailContent = document.getElementById('pos-widget-detail-content');
    elements.detailKicker = document.getElementById('pw-detail-kicker');
    elements.detailTitle = document.getElementById('pw-detail-title');
    elements.notesModal = document.getElementById('pos-widget-notes-modal');
    elements.notesContent = document.getElementById('pos-widget-notes-content');
    elements.notesKicker = document.getElementById('pw-notes-kicker');
    elements.notesTitle = document.getElementById('pw-notes-title');
    elements.declineModal = document.getElementById('pos-widget-decline-modal');
    elements.declineContent = document.getElementById('pos-widget-decline-content');
    elements.declineKicker = document.getElementById('pw-decline-kicker');
    elements.declineTitle = document.getElementById('pw-decline-title');
    elements.closeAllOrders = elements.allOrdersModal?.querySelector('[data-modal-close="all-orders"]') || null;
    elements.closeDetail = elements.detailModal?.querySelector('[data-modal-close="detail"]') || null;
    elements.closeNotes = elements.notesModal?.querySelector('[data-modal-close="notes"]') || null;
    elements.closeDecline = elements.declineModal?.querySelector('[data-modal-close="decline"]') || null;
  }

  function bindEvents() {
    window.addEventListener('message', handleInitMessage);
    window.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeTopModal();
      }
    });

    if (elements.bell) {
      elements.bell.addEventListener('click', () => {
        if (state.drafts.length === 0) {
          state.emptyStateOpen = !state.emptyStateOpen;
          state.panelOpen = state.emptyStateOpen;
        } else {
          state.emptyStateOpen = false;
          state.panelOpen = !state.panelOpen;
        }
        render();
      });
    }

    if (elements.soundToggle) {
      elements.soundToggle.addEventListener('click', () => {
        setNotificationMuted(!isMuted());
        if (!isMuted() && state.drafts.length) {
          startContinuousBeep('new');
        }
        render();
      });
    }

    if (elements.stack) {
      elements.stack.addEventListener('click', handleStackClick);
    }

    if (elements.allOrdersContent) {
      elements.allOrdersContent.addEventListener('click', handleAllOrdersClick);
    }

    if (elements.detailContent) {
      elements.detailContent.addEventListener('click', handleDetailClick);
    }

    if (elements.notesContent) {
      elements.notesContent.addEventListener('click', handleNotesClick);
    }

    if (elements.declineContent) {
      elements.declineContent.addEventListener('click', handleDeclineClick);
      elements.declineContent.addEventListener('submit', handleDeclineSubmit);
    }

    [elements.allOrdersModal, elements.detailModal, elements.notesModal, elements.declineModal].forEach((modal) => {
      if (!modal) return;
      modal.addEventListener('click', (event) => {
        const closeTarget = event.target.closest('[data-modal-close]');
        if (!closeTarget) return;
        const modalName = closeTarget.getAttribute('data-modal-close');
        closeModal(modalName);
      });
    });
  }

  async function handleInitMessage(event) {
    const payload = event && typeof event.data === 'object' ? event.data : null;
    if (!payload) {
      return;
    }
    if (payload.type === 'cofe.host.order-result') {
      handleHostOrderResult(event, payload);
      return;
    }
    if (payload.type === 'cofe.host.open' || payload.type === 'cofe.host.toggle' || payload.type === 'cofe.host.close') {
      handleHostControlMessage(payload);
      return;
    }
    if (payload.type !== 'cofe.init') {
      return;
    }
    const nextToken = typeof payload.deviceToken === 'string' ? payload.deviceToken.trim() : '';
    if (!nextToken) {
      showToast(t('missingDeviceToken'));
      return;
    }
    const nextLocale = normalizeLocale(payload.locale || payload.language);
    const nextHostBellMode = Boolean(payload.hostBellMode || payload.displayMode === 'host_bell');
    const nextNavbarBellMode = Boolean(payload.navbarBellMode || payload.displayMode === 'navbar_bell' || payload.presentation === 'navbar');
    const nextOrigin = typeof event.origin === 'string' ? event.origin.trim() : '';
    const nextDisplayMode = nextHostBellMode ? 'host-bell' : (nextNavbarBellMode ? 'navbar-bell' : 'widget-bell');
    const nextInitKey = `${nextToken}::${nextOrigin}::${nextLocale}::${nextDisplayMode}`;
    if (state.activeInitKey === nextInitKey && state.bootstrap) {
      return;
    }
    state.deviceToken = nextToken;
    state.parentOrigin = nextOrigin || null;
    state.locale = nextLocale;
    state.hostBellMode = nextHostBellMode;
    state.navbarBellMode = nextNavbarBellMode;
    state.activeInitKey = nextInitKey;
    applyLocaleChrome();
    await initializeWidget(nextInitKey);
  }

  function handleHostControlMessage(payload) {
    if (!state.hostBellMode && !state.navbarBellMode) {
      return;
    }
    if (payload.type === 'cofe.host.close') {
      closeAllSurfaces();
      return;
    }
    if (!state.drafts.length) {
      state.panelOpen = false;
      state.emptyStateOpen = false;
      render();
      return;
    }
    state.emptyStateOpen = false;
    state.panelOpen = payload.type === 'cofe.host.toggle' ? !state.panelOpen : true;
    render();
  }

  async function initializeWidget(initKey) {
    cleanupRealtime();
    resetStateForInit();
    render();
    try {
      const bootstrap = await apiFetch('/api/integrations/pos/local-bridge/widget/bootstrap');
      if (!isActiveInit(initKey)) {
        return;
      }
      state.bootstrap = bootstrap;
      state.device = bootstrap.device || null;
      initNotificationSounds();
      state.panelOpen = false;
      state.emptyStateOpen = false;
      startPolling(initKey);
      startHeartbeat(initKey);
      connectWebSocket(initKey);
      await refreshPending({ initKey, forceOpen: true, silent: true });
      postToParent('cofe.widget.connected', {
        deviceId: state.device?.id || null,
        connectionId: state.device?.connectionId || null,
      });
    } catch (error) {
      if (!isActiveInit(initKey)) {
        return;
      }
      showToast(error.message || t('failedStartWidget'));
      postToParent('cofe.widget.error', {
        message: error.message || t('failedStartWidget'),
        code: error.code || null,
      });
    }
  }

  function resetStateForInit() {
    state.bootstrap = null;
    state.device = null;
    state.drafts = [];
    stopContinuousBeep();
    state.commands = [];
    state.panelOpen = false;
    state.emptyStateOpen = false;
    state.hostBellMode = Boolean(state.hostBellMode);
    state.navbarBellMode = Boolean(state.navbarBellMode);
    state.detailDraftId = null;
    state.notesDraftId = null;
    state.declineDraftId = null;
    state.declineVia = 'overlay';
    state.allOrdersOpen = false;
    state.wsConnected = false;
    state.confirmingDraftIds.clear();
    state.confirmingNotesIds.clear();
    state.decliningDraftIds.clear();
    clearPendingHostOrderResults(createWidgetError(t('failedConfirmOrder'), 409, 'host_order_cancelled'));
  }

  function cleanupRealtime() {
    if (state.pollTimer) {
      clearInterval(state.pollTimer);
      state.pollTimer = null;
    }
    if (state.heartbeatTimer) {
      clearInterval(state.heartbeatTimer);
      state.heartbeatTimer = null;
    }
    if (state.reconnectTimer) {
      clearTimeout(state.reconnectTimer);
      state.reconnectTimer = null;
    }
    if (state.websocket) {
      try {
        state.websocket.close();
      } catch {
        // Ignore close failures.
      }
      state.websocket = null;
    }
    state.wsConnected = false;
  }

  function getWidgetSoundConfig() {
    const widgetConfig = state.bootstrap?.config?.widget && typeof state.bootstrap.config.widget === 'object'
      ? state.bootstrap.config.widget
      : {};
    const soundUrl = asText(
      widgetConfig.notificationSoundUrl
      || widgetConfig.soundUrl
      || widgetConfig.orderNotificationSoundUrl
      || ORDER_NOTIFICATION_SOUND_URL,
    );
    const intervalMs = clampInterval(
      widgetConfig.notificationSoundIntervalMs || widgetConfig.soundIntervalMs,
      NOTIFICATION_SOUND_INTERVAL_MS,
    );
    const volumeValue = Number(widgetConfig.notificationSoundVolume ?? widgetConfig.soundVolume);
    const volume = Number.isFinite(volumeValue)
      ? Math.max(0, Math.min(1, volumeValue))
      : NOTIFICATION_SOUND_VOLUME;
    return {
      enabled: widgetConfig.soundEnabled !== false,
      soundUrl: soundUrl || ORDER_NOTIFICATION_SOUND_URL,
      intervalMs,
      volume,
      muteStorageKey: asText(widgetConfig.muteStorageKey) || NOTIFICATION_MUTE_STORAGE_KEY,
    };
  }

  function initNotificationSounds() {
    const config = getWidgetSoundConfig();
    if (!config.enabled || typeof window.Audio !== 'function') {
      state.notificationAudio = null;
      state.notificationAudioUrl = null;
      return;
    }
    if (state.notificationAudio && state.notificationAudioUrl === config.soundUrl) {
      state.notificationAudio.volume = config.volume;
      return;
    }
    try {
      const audio = new Audio(config.soundUrl);
      audio.preload = 'auto';
      audio.volume = config.volume;
      state.notificationAudio = audio;
      state.notificationAudioUrl = config.soundUrl;
    } catch (error) {
      state.notificationAudio = null;
      state.notificationAudioUrl = null;
      console.warn('[warn] Failed to initialize POS widget notification audio:', error);
    }
  }

  function playAudioClip(audio) {
    if (!audio) return false;
    try {
      audio.pause();
      audio.currentTime = 0;
      const playPromise = audio.play();
      if (playPromise && typeof playPromise.catch === 'function') {
        playPromise.catch((error) => {
          console.warn('[warn] POS widget notification sound play failed:', error);
        });
      }
      return true;
    } catch (error) {
      console.warn('[warn] POS widget notification sound error:', error);
      return false;
    }
  }

  function playNotificationSound() {
    if (isMuted()) return;
    const config = getWidgetSoundConfig();
    if (!config.enabled) return;
    if (!state.notificationAudio || state.notificationAudioUrl !== config.soundUrl) {
      initNotificationSounds();
    }
    if (state.notificationAudio) {
      state.notificationAudio.volume = config.volume;
    }
    playAudioClip(state.notificationAudio);
  }

  function startContinuousBeep() {
    if (state.soundLoopInterval) {
      clearInterval(state.soundLoopInterval);
      state.soundLoopInterval = null;
    }
    if (isMuted() || !hasAnyNotifications()) {
      return;
    }
    playNotificationSound();
    const intervalMs = getWidgetSoundConfig().intervalMs;
    state.soundLoopInterval = window.setInterval(() => {
      if (hasAnyNotifications() && !isMuted()) {
        playNotificationSound();
        return;
      }
      stopContinuousBeep();
    }, intervalMs);
  }

  function stopContinuousBeep() {
    if (state.soundLoopInterval) {
      clearInterval(state.soundLoopInterval);
      state.soundLoopInterval = null;
    }
  }

  function syncNotificationSoundLoop() {
    if (!hasAnyNotifications() || isMuted() || getWidgetSoundConfig().enabled === false) {
      stopContinuousBeep();
    }
  }

  function hasAnyNotifications() {
    return state.drafts.length > 0;
  }

  function isMuted() {
    return Boolean(state.notificationMuted);
  }

  function setNotificationMuted(value) {
    state.notificationMuted = Boolean(value);
    try {
      window.localStorage.setItem(getWidgetSoundConfig().muteStorageKey, state.notificationMuted ? '1' : '0');
    } catch {
      // Mute still works for this iframe session when storage is unavailable.
    }
    if (state.notificationMuted) {
      stopContinuousBeep();
    }
  }

  function isActiveInit(initKey) {
    return Boolean(initKey && state.activeInitKey === initKey && state.deviceToken);
  }

  function startPolling(initKey) {
    const intervalMs = clampInterval(state.bootstrap?.config?.moova?.pollIntervalMs, DEFAULT_POLL_INTERVAL_MS);
    state.pollTimer = window.setInterval(() => {
      if (!isActiveInit(initKey)) return;
      refreshPending({ initKey, silent: true });
    }, intervalMs);
  }

  function startHeartbeat(initKey) {
    const intervalMs = clampInterval(state.bootstrap?.config?.moova?.heartbeatIntervalMs, DEFAULT_HEARTBEAT_INTERVAL_MS);
    state.heartbeatTimer = window.setInterval(async () => {
      if (!isActiveInit(initKey)) return;
      try {
        const result = await apiFetch('/api/integrations/pos/local-bridge/heartbeat', {
          method: 'POST',
          body: {
            lastError: null,
            metadata: {
              widget: {
                source: WIDGET_SOURCE,
                buildVersion: BUILD_VERSION,
                embedded: true,
              },
            },
          },
        });
        if (isActiveInit(initKey)) {
          state.device = result.device || state.device;
        }
      } catch {
        // Polling continues even if heartbeat fails transiently.
      }
    }, intervalMs);
  }

  function connectWebSocket(initKey) {
    const rawUrl = state.bootstrap?.config?.moova?.websocketUrl || buildFallbackWebSocketUrl();
    if (!rawUrl) {
      return;
    }
    let socketUrl;
    try {
      socketUrl = new URL(rawUrl);
    } catch {
      return;
    }
    socketUrl.searchParams.set('role', 'bridge');
    if (state.device?.shopId) socketUrl.searchParams.set('shopId', state.device.shopId);
    if (state.device?.connectionId) socketUrl.searchParams.set('connectionId', state.device.connectionId);
    if (state.device?.id) socketUrl.searchParams.set('deviceId', state.device.id);
    if (state.parentOrigin) socketUrl.searchParams.set('parentOrigin', state.parentOrigin);

    let socket;
    try {
      socket = new window.WebSocket(socketUrl.toString(), state.deviceToken);
    } catch {
      return;
    }

    state.websocket = socket;
    socket.addEventListener('open', () => {
      if (!isActiveInit(initKey)) {
        try {
          socket.close();
        } catch {
          // Ignore close failures.
        }
        return;
      }
      state.wsConnected = true;
      render();
    });

    socket.addEventListener('message', (event) => {
      if (!isActiveInit(initKey)) return;
      const payload = safeParseJson(event.data);
      if (!payload || typeof payload.type !== 'string') return;
      if (payload.type === 'pos.bridge_draft.upsert') {
        showToast(t('newOrderToast'));
        refreshPending({ initKey, silent: true, forceOpen: true });
      }
    });

    socket.addEventListener('close', () => {
      if (state.websocket === socket) {
        state.websocket = null;
      }
      state.wsConnected = false;
      render();
      if (!isActiveInit(initKey)) return;
      state.reconnectTimer = window.setTimeout(() => connectWebSocket(initKey), 2800);
    });

    socket.addEventListener('error', () => {
      state.wsConnected = false;
      render();
    });
  }

  async function refreshPending(options) {
    const initKey = options?.initKey || state.activeInitKey;
    if (!isActiveInit(initKey)) {
      return null;
    }
    if (state.refreshPromise) {
      return state.refreshPromise;
    }
    state.refreshPromise = (async () => {
      try {
        const result = await apiFetch('/api/integrations/pos/local-bridge/pending');
        if (!isActiveInit(initKey)) {
          return result;
        }
        const previousCount = state.drafts.length;
        const previousIds = new Set(state.drafts.map((draft) => String(draft.id)));
        state.device = result.device || state.device;
        state.commands = Array.isArray(result.commands) ? result.commands : [];
        state.drafts = Array.isArray(result.drafts) ? result.drafts.slice() : [];
        const hasNewDraft = state.drafts.length > previousCount
          || state.drafts.some((draft) => !previousIds.has(String(draft.id)));
        const shouldNotify = Boolean(state.drafts.length && (options?.forceOpen || hasNewDraft));
        if (shouldNotify) {
          state.emptyStateOpen = false;
          state.panelOpen = true;
        }
        if (shouldNotify) {
          showToast(t('newOrderToast'));
          startContinuousBeep('new');
        } else {
          syncNotificationSoundLoop();
        }
        if (!state.drafts.length) {
          state.panelOpen = state.emptyStateOpen;
        }
        reconcileModalState();
        render();
        return result;
      } catch (error) {
        if (!options?.silent && isActiveInit(initKey)) {
      showToast(error.message || t('failedFetchPending'));
      postToParent('cofe.widget.error', {
        message: error.message || t('failedFetchPending'),
        code: error.code || null,
      });
        }
        throw error;
      } finally {
        state.refreshPromise = null;
      }
    })();
    return state.refreshPromise;
  }

  function reconcileModalState() {
    if (state.detailDraftId && !findDraft(state.detailDraftId)) {
      state.detailDraftId = null;
    }
    if (state.notesDraftId && !findDraft(state.notesDraftId)) {
      state.notesDraftId = null;
    }
    if (state.declineDraftId && !findDraft(state.declineDraftId)) {
      state.declineDraftId = null;
      state.declineVia = 'overlay';
    }
    if (state.allOrdersOpen && !state.drafts.length) {
      state.allOrdersOpen = false;
    }
  }

  function handleStackClick(event) {
    const actionButton = event.target.closest('[data-action]');
    if (actionButton) {
      event.stopPropagation();
      const action = actionButton.getAttribute('data-action');
      const draftId = actionButton.getAttribute('data-draft-id');
      if (action === 'show-more') {
        state.allOrdersOpen = true;
        render();
      } else if (action === 'open-notes' && draftId) {
        openNotesModal(draftId);
      } else if (action === 'confirm-order' && draftId) {
        confirmOrder(draftId, actionButton.getAttribute('data-confirmed-via') || 'overlay');
      } else if (action === 'open-decline' && draftId) {
        openDeclineModal(draftId, actionButton.getAttribute('data-declined-via') || 'overlay');
      }
      return;
    }
    const card = event.target.closest('[data-draft-card]');
    if (card) {
      openDetailModal(card.getAttribute('data-draft-id'));
    }
  }

  function handleAllOrdersClick(event) {
    const actionButton = event.target.closest('[data-action]');
    if (!actionButton) return;
    const action = actionButton.getAttribute('data-action');
    const draftId = actionButton.getAttribute('data-draft-id');
    if (action === 'open-detail' && draftId) {
      state.allOrdersOpen = false;
      openDetailModal(draftId);
    }
  }

  function handleDetailClick(event) {
    const actionButton = event.target.closest('[data-action]');
    if (!actionButton) return;
    const action = actionButton.getAttribute('data-action');
    const draftId = actionButton.getAttribute('data-draft-id');
    if (!draftId) return;
    if (action === 'open-notes') {
      openNotesModal(draftId);
    } else if (action === 'confirm-order') {
      confirmOrder(draftId, actionButton.getAttribute('data-confirmed-via') || 'detail_modal');
    } else if (action === 'open-decline') {
      openDeclineModal(draftId, actionButton.getAttribute('data-declined-via') || 'detail_modal');
    }
  }

  function handleNotesClick(event) {
    const actionButton = event.target.closest('[data-action]');
    if (!actionButton) return;
    const action = actionButton.getAttribute('data-action');
    const draftId = actionButton.getAttribute('data-draft-id');
    if (action === 'confirm-notes' && draftId) {
      confirmNotes(draftId);
    } else if (action === 'confirm-notes-and-order' && draftId) {
      confirmNotesAndOrder(draftId);
    }
  }

  function handleDeclineClick(event) {
    const actionButton = event.target.closest('[data-action]');
    if (!actionButton) return;
    const action = actionButton.getAttribute('data-action');
    if (action === 'cancel-decline') {
      closeModal('decline');
    }
  }

  function handleDeclineSubmit(event) {
    event.preventDefault();
    const draftId = state.declineDraftId;
    if (!draftId) return;
    const reasonInput = document.getElementById('pw-decline-reason');
    const reason = asText(reasonInput?.value);
    declineOrder(draftId, reason, state.declineVia || 'decline_modal');
  }

  function openDetailModal(draftId) {
    if (!findDraft(draftId)) return;
    state.detailDraftId = draftId;
    render();
  }

  function openNotesModal(draftId) {
    if (!findDraft(draftId)) return;
    state.notesDraftId = draftId;
    render();
  }

  function openDeclineModal(draftId, declinedVia) {
    if (!findDraft(draftId)) return;
    state.declineDraftId = draftId;
    state.declineVia = declinedVia || 'overlay';
    render();
    setTimeout(() => {
      const input = document.getElementById('pw-decline-reason');
      if (input && typeof input.focus === 'function') {
        input.focus();
      }
    }, 0);
  }

  function closeTopModal() {
    if (state.declineDraftId) {
      state.declineDraftId = null;
      state.declineVia = 'overlay';
    } else if (state.notesDraftId) {
      state.notesDraftId = null;
    } else if (state.detailDraftId) {
      state.detailDraftId = null;
    } else if (state.allOrdersOpen) {
      state.allOrdersOpen = false;
    }
    render();
  }

  function closeAllSurfaces() {
    if (state.toastTimer) {
      window.clearTimeout(state.toastTimer);
      state.toastTimer = null;
    }
    state.toastVisible = false;
    state.toastMessage = '';
    if (elements.toast) {
      elements.toast.hidden = true;
      elements.toast.textContent = '';
    }
    state.panelOpen = false;
    state.emptyStateOpen = false;
    state.allOrdersOpen = false;
    state.detailDraftId = null;
    state.notesDraftId = null;
    state.declineDraftId = null;
    state.declineVia = 'overlay';
    render();
  }

  function closeModal(modalName) {
    if (modalName === 'all-orders') {
      state.allOrdersOpen = false;
    } else if (modalName === 'detail') {
      state.detailDraftId = null;
    } else if (modalName === 'notes') {
      state.notesDraftId = null;
    } else if (modalName === 'decline') {
      state.declineDraftId = null;
      state.declineVia = 'overlay';
    }
    render();
  }

  async function confirmNotes(draftId) {
    const draft = findDraft(draftId);
    if (!draft || state.confirmingNotesIds.has(draftId)) {
      return;
    }
    state.confirmingNotesIds.add(draftId);
    render();
    try {
      const result = await apiFetch(`/api/integrations/pos/local-bridge/drafts/${encodeURIComponent(draftId)}/confirm-notes`, {
        method: 'POST',
        body: {
          localConfirmedAt: new Date().toISOString(),
        },
      });
      applyDraftResult(result.draft);
      state.notesDraftId = null;
      showToast(t('notesConfirmedToast'));
    } catch (error) {
      showToast(error.message || t('failedConfirmNotes'));
    } finally {
      state.confirmingNotesIds.delete(draftId);
      render();
    }
  }

  async function confirmNotesAndOrder(draftId) {
    const normalizedDraftId = String(draftId);
    const draft = findDraft(normalizedDraftId);
    if (
      !draft
      || state.confirmingNotesIds.has(normalizedDraftId)
      || state.confirmingDraftIds.has(normalizedDraftId)
    ) {
      return;
    }
    state.confirmingNotesIds.add(normalizedDraftId);
    render();
    try {
      const result = await apiFetch(`/api/integrations/pos/local-bridge/drafts/${encodeURIComponent(normalizedDraftId)}/confirm-notes`, {
        method: 'POST',
        body: {
          localConfirmedAt: new Date().toISOString(),
        },
      });
      applyDraftResult(result.draft);
      state.confirmingNotesIds.delete(normalizedDraftId);
      render();
      await confirmOrder(normalizedDraftId, 'notes_modal');
    } catch (error) {
      showToast(error.message || t('failedConfirmNotes'));
    } finally {
      state.confirmingNotesIds.delete(normalizedDraftId);
      render();
    }
  }

  async function confirmOrder(draftId, confirmedVia) {
    const draft = findDraft(draftId);
    if (!draft || state.confirmingDraftIds.has(draftId)) {
      return;
    }
    if (draftNeedsNotes(draft) && !draft.notesConfirmedAt) {
      state.notesDraftId = draftId;
      render();
      showToast(t('confirmNotesFirst'));
      return;
    }
    state.confirmingDraftIds.add(draftId);
    render();
    try {
      let result;
      if (isIframeWidgetMode()) {
        const hostResult = await sendDraftToHostForCreation(draft, confirmedVia || 'overlay');
        result = await apiFetch(`/api/integrations/pos/local-bridge/drafts/${encodeURIComponent(draftId)}/ack-created`, {
          method: 'POST',
          body: {
            providerOrderId: asText(hostResult.providerOrderId) || null,
            providerReferenceId: asText(hostResult.providerReferenceId) || null,
            providerStatus: asText(hostResult.providerStatus) || 'accepted',
            responsePayload: {
              source: WIDGET_SOURCE,
              confirmedVia: confirmedVia || 'overlay',
              buildVersion: BUILD_VERSION,
              hostResponse: hostResult.responsePayload || hostResult,
            },
          },
        });
      } else {
        result = await apiFetch(`/api/integrations/pos/local-bridge/drafts/${encodeURIComponent(draftId)}/ack-created`, {
          method: 'POST',
          body: {
            providerStatus: 'accepted',
            responsePayload: {
              source: WIDGET_SOURCE,
              confirmedVia: confirmedVia || 'overlay',
              buildVersion: BUILD_VERSION,
            },
          },
        });
      }
      applyDraftResult(result.draft);
      if (state.detailDraftId === draftId) {
        state.detailDraftId = null;
      }
      if (state.notesDraftId === draftId) {
        state.notesDraftId = null;
      }
      postToParent('cofe.widget.order-confirmed', {
        draftId,
        orderId: draft.orderId || null,
      });
      refreshPending({ silent: true });
    } catch (error) {
      if (error.code === 'notes_not_confirmed') {
        state.notesDraftId = draftId;
        showToast(t('confirmNotesFirst'));
      } else {
        if (isIframeWidgetMode() && isHostOrderCreationFailure(error)) {
          await acknowledgeHostOrderFailure(draftId, error, confirmedVia || 'overlay');
        }
        showToast(error.message || t('failedConfirmOrder'));
      }
    } finally {
      state.confirmingDraftIds.delete(draftId);
      render();
    }
  }

  async function declineOrder(draftId, reason, declinedVia) {
    const draft = findDraft(draftId);
    if (!draft || state.decliningDraftIds.has(draftId)) {
      return;
    }
    state.decliningDraftIds.add(draftId);
    render();
    try {
      const body = {
        errorPayload: {
          source: WIDGET_SOURCE,
          declinedVia: declinedVia || 'decline_modal',
          buildVersion: BUILD_VERSION,
          ui: {
            hadDetailOpen: state.detailDraftId === draftId,
            hadNotesOpen: state.notesDraftId === draftId,
          },
        },
      };
      const normalizedReason = asText(reason);
      if (normalizedReason) {
        body.message = normalizedReason;
      }
      const result = await apiFetch(`/api/integrations/pos/local-bridge/drafts/${encodeURIComponent(draftId)}/ack-declined`, {
        method: 'POST',
        body,
      });
      applyDraftResult(result.draft);
      if (state.detailDraftId === draftId) {
        state.detailDraftId = null;
      }
      if (state.notesDraftId === draftId) {
        state.notesDraftId = null;
      }
      if (state.declineDraftId === draftId) {
        state.declineDraftId = null;
        state.declineVia = 'overlay';
      }
      showToast(t('orderDeclinedToast'));
      postToParent('cofe.widget.order-declined', {
        draftId,
        orderId: draft.orderId || null,
      });
      refreshPending({ silent: true });
    } catch (error) {
      showToast(error.message || t('failedDeclineOrder'));
    } finally {
      state.decliningDraftIds.delete(draftId);
      render();
    }
  }

  function isIframeWidgetMode() {
    const metadata = state.device && state.device.metadata && typeof state.device.metadata === 'object'
      ? state.device.metadata
      : {};
    const bridgeConfig = state.bootstrap?.config?.bridge && typeof state.bootstrap.config.bridge === 'object'
      ? state.bootstrap.config.bridge
      : {};
    const metadataType = String(metadata.integrationType || '').trim().toLowerCase();
    const bridgeType = String(bridgeConfig.integrationType || '').trim().toLowerCase();
    return metadataType === 'iframe_widget' || bridgeType === 'iframe_widget';
  }

  function sendDraftToHostForCreation(draft, confirmedVia) {
    const requestPayload = draft && draft.requestPayload && typeof draft.requestPayload === 'object'
      ? draft.requestPayload
      : null;
    if (!requestPayload || !Array.isArray(requestPayload.items) || requestPayload.items.length === 0) {
      const error = createWidgetError(t('missingHostOrderPayload'), 409, 'host_order_payload_missing');
      error.phase = 'host_prepare';
      return Promise.reject(error);
    }
    if (!window.parent || window.parent === window) {
      const error = createWidgetError(t('failedConfirmOrder'), 409, 'host_parent_missing');
      error.phase = 'host_prepare';
      return Promise.reject(error);
    }

    const draftId = String(draft.id);
    return new Promise((resolve, reject) => {
      const existing = state.pendingHostOrderResults.get(draftId);
      if (existing?.timeoutId) {
        clearTimeout(existing.timeoutId);
      }
      const timeoutId = window.setTimeout(() => {
        state.pendingHostOrderResults.delete(draftId);
        const error = createWidgetError(t('hostOrderAckTimeout'), 408, 'host_order_ack_timeout');
        error.phase = 'host_ack';
        reject(error);
      }, HOST_ORDER_ACK_TIMEOUT_MS);
      state.pendingHostOrderResults.set(draftId, {
        resolve,
        reject,
        timeoutId,
      });

      try {
        window.parent.postMessage(
          {
            type: 'cofe.order.confirmed',
            draftId,
            cofeOrderId: requestPayload.cofeOrderId || null,
            idempotencyKey: requestPayload.idempotencyKey || null,
            branchId: requestPayload.branchId || null,
            tableNumber: requestPayload.tableNumber || null,
            items: requestPayload.items,
          },
          state.parentOrigin || '*',
        );
      } catch (error) {
        clearTimeout(timeoutId);
        state.pendingHostOrderResults.delete(draftId);
        error.phase = 'host_post_message';
        reject(error);
      }
    });
  }

  function handleHostOrderResult(event, payload) {
    if (state.parentOrigin && event.origin !== state.parentOrigin) {
      return;
    }
    const draftId = asText(payload && payload.draftId);
    if (!draftId) {
      return;
    }
    const pending = state.pendingHostOrderResults.get(draftId);
    if (!pending) {
      return;
    }
    if (pending.timeoutId) {
      clearTimeout(pending.timeoutId);
    }
    state.pendingHostOrderResults.delete(draftId);
    if (payload.ok === true) {
      pending.resolve(payload);
      return;
    }
    const message = asText(payload.message) || t('failedConfirmOrder');
    const error = createWidgetError(message, 409, 'host_order_failed');
    error.phase = 'host_result';
    error.retryable = payload.retryable !== false;
    error.errorPayload = payload.errorPayload || payload;
    pending.reject(error);
  }

  function isHostOrderCreationFailure(error) {
    return String(error?.phase || '').startsWith('host_');
  }

  async function acknowledgeHostOrderFailure(draftId, error, confirmedVia) {
    try {
      const result = await apiFetch(`/api/integrations/pos/local-bridge/drafts/${encodeURIComponent(draftId)}/ack-failed`, {
        method: 'POST',
        body: {
          message: error?.message || t('failedConfirmOrder'),
          retryable: error?.retryable !== false,
          errorPayload: {
            source: WIDGET_SOURCE,
            confirmedVia,
            buildVersion: BUILD_VERSION,
            code: error?.code || null,
            hostResponse: error?.errorPayload || null,
          },
        },
      });
      applyDraftResult(result.draft);
    } catch {
      // Keep the draft visible; the next refresh can retry or report the backend state.
    }
  }

  function clearPendingHostOrderResults(error) {
    state.pendingHostOrderResults.forEach((entry) => {
      if (entry?.timeoutId) {
        clearTimeout(entry.timeoutId);
      }
      if (entry?.reject && error) {
        entry.reject(error);
      }
    });
    state.pendingHostOrderResults.clear();
  }

  function applyDraftResult(updatedDraft) {
    if (!updatedDraft || !updatedDraft.id) {
      return;
    }
    const terminalStatuses = new Set(['acked', 'declined', 'expired', 'cancelled']);
    if (terminalStatuses.has(String(updatedDraft.status || '').toLowerCase())) {
      state.drafts = state.drafts.filter((draft) => String(draft.id) !== String(updatedDraft.id));
    } else {
      const nextDrafts = state.drafts.slice();
      const index = nextDrafts.findIndex((draft) => String(draft.id) === String(updatedDraft.id));
      if (index >= 0) {
        nextDrafts[index] = updatedDraft;
      } else {
        nextDrafts.unshift(updatedDraft);
      }
      state.drafts = nextDrafts;
    }
    reconcileModalState();
    syncNotificationSoundLoop();
  }

  function render() {
    applyLocaleChrome();
    renderBell();
    renderSoundToggle();
    renderStack();
    renderAllOrdersModal();
    renderDetailModal();
    renderNotesModal();
    renderDeclineModal();
    syncParentSignals();
  }

  function renderBell() {
    if (!elements.bell || !elements.badge || !elements.bellMeta) return;
    const count = state.drafts.length;
    elements.bell.hidden = state.hostBellMode;
    if (state.hostBellMode) {
      return;
    }
    elements.bell.hidden = false;
    elements.badge.hidden = count === 0;
    elements.badge.textContent = formatNumber(count);
    if (!count) {
      elements.bellMeta.textContent = t('waitingForOrders');
      return;
    }
    const transport = state.wsConnected ? t('live') : t('syncing');
    elements.bellMeta.textContent = t('pendingOrdersMeta', {
      count: formatNumber(count),
      transport,
      isOne: count === 1,
    });
  }

  function renderSoundToggle() {
    if (!elements.soundToggle) return;
    const config = getWidgetSoundConfig();
    elements.soundToggle.hidden = state.hostBellMode || !config.enabled;
    if (elements.soundToggle.hidden) return;
    const muted = isMuted();
    const label = muted ? t('unmuteNotifications') : t('muteNotifications');
    elements.soundToggle.dataset.muted = muted ? 'true' : 'false';
    elements.soundToggle.setAttribute('aria-label', label);
    elements.soundToggle.setAttribute('aria-pressed', muted ? 'true' : 'false');
    elements.soundToggle.title = label;
    if (elements.soundLabel) {
      elements.soundLabel.textContent = label;
    }
  }

  function renderStack() {
    if (!elements.stack) return;
    if (!state.panelOpen) {
      elements.stack.hidden = true;
      elements.stack.innerHTML = '';
      return;
    }
    if (state.drafts.length === 0) {
      elements.stack.hidden = false;
      elements.stack.innerHTML = renderEmptyState(
        t('noOrdersWaitingTitle'),
        t('noOrdersWaitingCopy'),
      );
      return;
    }
    const topDrafts = state.drafts.slice(0, 3);
    const noticeMessage = state.toastMessage || (state.navbarBellMode && state.drafts.length ? t('pendingOrdersNotice') : '');
    const noticeHtml = noticeMessage
      ? `<div class="pw-stack-notice">${escapeHtml(noticeMessage)}</div>`
      : '';
    const cardsHtml = topDrafts.map((draft) => renderOverlayCard(draft)).join('');
    const footerHtml = state.drafts.length > 3
      ? `
        <div class="pw-stack-footer">
          <button class="pw-show-more" type="button" data-action="show-more">
            ${escapeHtml(t('showAll', { count: formatNumber(state.drafts.length) }))}
          </button>
        </div>
      `
      : '';
    elements.stack.hidden = false;
    elements.stack.innerHTML = `${noticeHtml}${cardsHtml}${footerHtml}`;
  }

  function renderOverlayCard(draft) {
    const ui = getUiPayload(draft);
    const status = String(draft.status || 'pending_confirmation').toLowerCase();
    const hasNotes = draftNeedsNotes(draft);
    const noteConfirmed = Boolean(draft.notesConfirmedAt);
    const isConfirming = state.confirmingDraftIds.has(String(draft.id));
    const isDeclining = state.decliningDraftIds.has(String(draft.id));
    const itemRows = renderItemLines(ui.items, { compact: true });
    const statusChip = status === 'failed'
      ? `<span class="pw-state-chip" data-state="failed">${escapeHtml(t('retryNeeded'))}</span>`
      : '';
    return `
      <article class="pw-card" data-draft-card="true" data-draft-id="${escapeHtml(String(draft.id))}" data-status="${escapeHtml(status)}">
        <div class="pw-card-top">
          <div>
            <p class="pw-card-kicker">${escapeHtml(t('table'))}</p>
            <h3 class="pw-card-title">${escapeHtml(getTableDisplay(ui.tableNumber))}</h3>
            <div class="pw-card-meta">
              <span>${escapeHtml(formatPlacedAt(ui.placedAt || draft.createdAt))}</span>
              ${draft.lastError && status === 'failed' ? `<span>${escapeHtml(draft.lastError)}</span>` : ''}
            </div>
          </div>
          ${statusChip}
        </div>
        <div class="pw-items">${itemRows}</div>
        <div class="pw-card-summary">
          <span class="pw-card-summary-label">${escapeHtml(t('total'))}</span>
          <strong class="pw-card-summary-value">${escapeHtml(formatCurrency(resolveTotal(ui), ui.summary?.currencyCode || ui.currencyCode))}</strong>
        </div>
        <div class="pw-card-actions">
          ${hasNotes ? renderNotesButton(draft) : ''}
          <button
            class="pw-button pw-button-danger"
            type="button"
            data-action="open-decline"
            data-draft-id="${escapeHtml(String(draft.id))}"
            data-declined-via="overlay"
            ${isConfirming || isDeclining ? 'disabled' : ''}
          >
            ${escapeHtml(isDeclining ? t('declining') : t('decline'))}
          </button>
          <button
            class="pw-button pw-button-primary"
            type="button"
            data-action="confirm-order"
            data-draft-id="${escapeHtml(String(draft.id))}"
            data-confirmed-via="overlay"
            ${isConfirming || isDeclining ? 'disabled' : ''}
          >
            ${escapeHtml(isConfirming ? t('confirming') : t('confirm'))}
          </button>
        </div>
      </article>
    `;
  }

  function renderNotesButton(draft) {
    const confirmed = Boolean(draft.notesConfirmedAt);
    return `
      <button
        class="pw-icon-button"
        type="button"
        data-action="open-notes"
        data-draft-id="${escapeHtml(String(draft.id))}"
        data-state="${confirmed ? 'confirmed' : 'pending'}"
        aria-label="${escapeHtml(confirmed ? t('notesConfirmedLabel') : t('reviewNotes'))}"
        title="${escapeHtml(confirmed ? t('notesConfirmedLabel') : t('reviewNotes'))}"
      >
        <svg viewBox="0 0 24 24" role="presentation" aria-hidden="true">
          <path d="M6 3h9l5 5v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm8 1.5V9h4.5L14 4.5ZM8 11h8v1.5H8V11Zm0 3.5h8V16H8v-1.5Zm0-7h4v1.5H8V7.5Z"></path>
        </svg>
      </button>
    `;
  }

  function renderAllOrdersModal() {
    if (!elements.allOrdersModal || !elements.allOrdersContent) return;
    elements.allOrdersModal.hidden = !state.allOrdersOpen;
    if (!state.allOrdersOpen) {
      elements.allOrdersContent.innerHTML = '';
      return;
    }
    if (!state.drafts.length) {
      elements.allOrdersContent.innerHTML = renderEmptyState(
        t('noOrdersWaitingTitle'),
        t('noOrdersWaitingCopy'),
      );
      return;
    }
    elements.allOrdersContent.innerHTML = `
      <div class="pw-queue">
        ${state.drafts.map((draft) => {
          const ui = getUiPayload(draft);
          const summaryLine = summarizeItems(ui.items);
          const noteLabel = draftNeedsNotes(draft)
            ? (draft.notesConfirmedAt ? t('notesConfirmedLabel') : t('notesPending'))
            : t('noNotes');
          return `
            <button class="pw-queue-row" type="button" data-action="open-detail" data-draft-id="${escapeHtml(String(draft.id))}">
              <div>
                <p class="pw-card-kicker">${escapeHtml(t('table'))}</p>
                <h3 class="pw-queue-title">${escapeHtml(getTableDisplay(ui.tableNumber))}</h3>
                <div class="pw-queue-meta">${escapeHtml(summaryLine)}</div>
              </div>
              <div style="text-align:right;">
                <div class="pw-card-summary-value">${escapeHtml(formatCurrency(resolveTotal(ui), ui.summary?.currencyCode || ui.currencyCode))}</div>
                <div class="pw-queue-meta">${escapeHtml(noteLabel)}</div>
              </div>
            </button>
          `;
        }).join('')}
      </div>
    `;
  }

  function renderDetailModal() {
    if (!elements.detailModal || !elements.detailContent) return;
    const draft = findDraft(state.detailDraftId);
    elements.detailModal.hidden = !draft;
    if (!draft) {
      elements.detailContent.innerHTML = '';
      return;
    }
    const ui = getUiPayload(draft);
    const hasNotes = draftNeedsNotes(draft);
    const noteConfirmed = Boolean(draft.notesConfirmedAt);
    const isConfirming = state.confirmingDraftIds.has(String(draft.id));
    const isDeclining = state.decliningDraftIds.has(String(draft.id));
    const items = Array.isArray(ui.items) ? ui.items : [];
    elements.detailContent.innerHTML = `
      <div class="pw-detail-header">
        <div class="pw-detail-hero">
          <div>
            <p class="pw-card-kicker">${escapeHtml(t('table'))}</p>
            <h3 class="pw-detail-table">${escapeHtml(getTableDisplay(ui.tableNumber))}</h3>
            <div class="pw-detail-meta">${escapeHtml(formatPlacedAt(ui.placedAt || draft.createdAt))}</div>
          </div>
          <div class="pw-detail-total">${escapeHtml(formatCurrency(resolveTotal(ui), ui.summary?.currencyCode || ui.currencyCode))}</div>
        </div>
        ${draft.lastError && String(draft.status || '').toLowerCase() === 'failed'
          ? `<div class="pw-detail-note-callout">${escapeHtml(draft.lastError)}</div>`
          : ''}
        ${hasNotes && !noteConfirmed
          ? `<div class="pw-detail-note-callout">${escapeHtml(t('reviewNotesFirstCallout'))}</div>`
          : ''}
      </div>
      <div class="pw-detail-grid">
        <section class="pw-detail-card">
          <div class="pw-detail-items">
            ${items.map((item) => `
              <div class="pw-detail-item">
                <div class="pw-detail-item-main">
                  <div class="pw-detail-item-name">${escapeHtml(formatItemLabel(item))}</div>
                  <div class="pw-detail-item-price">${escapeHtml(formatDetailItemMeta(item, ui.summary?.currencyCode || ui.currencyCode))}</div>
                </div>
                ${item && item.note ? `<div class="pw-detail-item-note">${escapeHtml(item.note)}</div>` : ''}
              </div>
            `).join('')}
          </div>
        </section>
      </div>
      <div class="pw-detail-actions">
        ${hasNotes ? renderNotesButton(draft) : ''}
        <button
          class="pw-button pw-button-danger"
          type="button"
          data-action="open-decline"
          data-draft-id="${escapeHtml(String(draft.id))}"
          data-declined-via="detail_modal"
          ${isConfirming || isDeclining ? 'disabled' : ''}
        >
          ${escapeHtml(isDeclining ? t('declining') : t('declineOrder'))}
        </button>
        <button
          class="pw-button pw-button-primary"
          type="button"
          data-action="confirm-order"
          data-draft-id="${escapeHtml(String(draft.id))}"
          data-confirmed-via="detail_modal"
          ${isConfirming || isDeclining ? 'disabled' : ''}
        >
          ${escapeHtml(isConfirming ? t('confirming') : t('confirmOrder'))}
        </button>
      </div>
    `;
  }

  function renderNotesModal() {
    if (!elements.notesModal || !elements.notesContent) return;
    const draft = findDraft(state.notesDraftId);
    elements.notesModal.hidden = !draft;
    if (!draft) {
      elements.notesContent.innerHTML = '';
      return;
    }
    const notes = getDraftNotes(draft);
    const isConfirming = state.confirmingNotesIds.has(String(draft.id));
    const isConfirmingOrder = state.confirmingDraftIds.has(String(draft.id));
    const isBusy = isConfirming || isConfirmingOrder;
    const confirmed = Boolean(draft.notesConfirmedAt);
    elements.notesContent.innerHTML = `
      ${
        !notes.hasNotes
          ? renderEmptyState(t('noNotesAttachedTitle'), t('noNotesAttachedCopy'))
          : `
            <div class="pw-notes-list">
              ${notes.orderNotes.map((entry) => `
                <section class="pw-notes-block">
                  <p class="pw-notes-label">${escapeHtml(entry.label)}</p>
                  <div class="pw-notes-order-copy">${formatMultiline(entry.text)}</div>
                </section>
              `).join('')}
              ${notes.itemNotes.map((entry) => `
                <section class="pw-notes-block">
                  <p class="pw-notes-label">${escapeHtml(t('itemNote'))}</p>
                  <div class="pw-notes-item">
                    <div class="pw-notes-item-name">${escapeHtml(entry.label)}</div>
                    <div class="pw-notes-item-note">${formatMultiline(entry.note)}</div>
                  </div>
                </section>
              `).join('')}
            </div>
          `
      }
      <div class="pw-notes-actions" style="margin-top:18px;">
        ${confirmed
          ? `<button class="pw-button pw-button-secondary" type="button" disabled>${escapeHtml(t('notesConfirmedLabel'))}</button>`
          : `
            <button
              class="pw-button pw-button-primary"
              type="button"
              data-action="confirm-notes"
              data-draft-id="${escapeHtml(String(draft.id))}"
              ${isBusy ? 'disabled' : ''}
            >
              ${escapeHtml(isConfirming ? t('saving') : t('confirmNotes'))}
            </button>
            <button
              class="pw-button pw-button-primary"
              type="button"
              data-action="confirm-notes-and-order"
              data-draft-id="${escapeHtml(String(draft.id))}"
              ${isBusy ? 'disabled' : ''}
            >
              ${escapeHtml(isBusy ? t(isConfirmingOrder ? 'confirming' : 'saving') : t('confirmNotesAndOrder'))}
            </button>
          `
        }
      </div>
    `;
  }

  function renderDeclineModal() {
    if (!elements.declineModal || !elements.declineContent) return;
    const draft = findDraft(state.declineDraftId);
    elements.declineModal.hidden = !draft;
    if (!draft) {
      elements.declineContent.innerHTML = '';
      return;
    }
    const ui = getUiPayload(draft);
    const isDeclining = state.decliningDraftIds.has(String(draft.id));
    elements.declineContent.innerHTML = `
      <form id="pw-decline-form" class="pw-decline-form">
        <div class="pw-decline-summary">
          <p class="pw-card-kicker">${escapeHtml(t('table'))}</p>
          <h3 class="pw-queue-title">${escapeHtml(getTableDisplay(ui.tableNumber))}</h3>
          <div class="pw-queue-meta">${escapeHtml(summarizeItems(ui.items))}</div>
        </div>
        <label class="pw-decline-label" for="pw-decline-reason">${escapeHtml(t('declineReasonLabel'))}</label>
        <textarea
          id="pw-decline-reason"
          class="pw-decline-textarea"
          maxlength="500"
          placeholder="${escapeHtml(t('declineReasonPlaceholder'))}"
        ></textarea>
        <div class="pw-decline-hint">${escapeHtml(t('declineReasonHint'))}</div>
        <div class="pw-decline-actions">
          <button class="pw-button pw-button-secondary" type="button" data-action="cancel-decline" ${isDeclining ? 'disabled' : ''}>
            ${escapeHtml(t('cancelDecline'))}
          </button>
          <button class="pw-button pw-button-danger" type="submit" ${isDeclining ? 'disabled' : ''}>
            ${escapeHtml(isDeclining ? t('declining') : t('submitDecline'))}
          </button>
        </div>
      </form>
    `;
  }

  function syncParentSignals() {
    const visible = Boolean(state.panelOpen || state.detailDraftId || state.notesDraftId || state.declineDraftId || state.allOrdersOpen);
    const count = state.drafts.length;
    if (state.lastSignals.visible !== visible) {
      state.lastSignals.visible = visible;
      postToParent('cofe.widget.visibility', { visible });
    }
    if (state.lastSignals.count !== count) {
      state.lastSignals.count = count;
      postToParent('cofe.widget.count', { count });
    }
    const frame = resolveParentFrame();
    const frameKey = `${frame.mode}:${frame.width}:${frame.height}`;
    if (state.lastSignals.frame !== frameKey) {
      state.lastSignals.frame = frameKey;
      postToParent('cofe.widget.frame', frame);
    }
  }

  function resolveParentFrame() {
    if (state.hostBellMode) {
      if (state.allOrdersOpen) {
        return measureFrameElement('modal', elements.allOrdersModal?.querySelector('.pw-dialog'), {
          width: 904,
          height: 760,
          maxWidth: 904,
          maxHeight: 760,
        });
      }
      if (state.detailDraftId) {
        return measureFrameElement('modal', elements.detailModal?.querySelector('.pw-dialog'), {
          width: 684,
          height: 760,
          maxWidth: 684,
          maxHeight: 760,
        });
      }
      if (state.notesDraftId) {
        return measureFrameElement('modal', elements.notesModal?.querySelector('.pw-dialog'), {
          width: 604,
          height: 640,
          maxWidth: 604,
          maxHeight: 640,
        });
      }
      if (state.declineDraftId) {
        return measureFrameElement('modal', elements.declineModal?.querySelector('.pw-dialog'), {
          width: 604,
          height: 560,
          maxWidth: 604,
          maxHeight: 560,
        });
      }
      if (state.panelOpen && state.drafts.length) {
        return measureFrameElement('panel', elements.stack, {
          width: 396,
          height: 160,
          maxWidth: 396,
          maxHeight: 760,
        });
      }
      return { mode: 'idle', width: 408, height: 1 };
    }
    if (state.allOrdersOpen) {
      return { mode: 'modal', width: 904, height: 760 };
    }
    if (state.detailDraftId) {
      return { mode: 'modal', width: 684, height: 760 };
    }
    if (state.notesDraftId) {
      return { mode: 'modal', width: 604, height: 640 };
    }
    if (state.declineDraftId) {
      return { mode: 'modal', width: 604, height: 560 };
    }
    if (state.panelOpen) {
      const visibleCards = state.drafts.length ? Math.min(Math.max(state.drafts.length, 1), 3) : 1;
      const footerHeight = state.drafts.length > 3 ? 64 : 0;
      const emptyStateAdjustment = state.drafts.length ? 0 : 24;
      return {
        mode: 'panel',
        width: 432,
        height: Math.min(760, 96 + (visibleCards * 188) + footerHeight + emptyStateAdjustment),
      };
    }
    if (state.toastVisible) {
      return { mode: 'toast', width: 396, height: 104 };
    }
    if (state.navbarBellMode) {
      return { mode: 'idle', width: 74, height: 38 };
    }
    return { mode: 'idle', width: 146, height: 94 };
  }

  function measureFrameElement(mode, element, fallback) {
    if (!element) {
      return { mode, width: fallback.width, height: fallback.height };
    }
    const rect = element.getBoundingClientRect();
    const width = Math.ceil(Math.max(rect.width, element.scrollWidth || 0, fallback.width));
    const height = Math.ceil(Math.max(rect.height, element.scrollHeight || 0, fallback.height));
    return {
      mode,
      width: Math.min(width, fallback.maxWidth || fallback.width),
      height: Math.min(height, fallback.maxHeight || fallback.height),
    };
  }

  function postToParent(type, payload) {
    if (!window.parent || window.parent === window) {
      return;
    }
    try {
      window.parent.postMessage(
        {
          type,
          ...payload,
        },
        state.parentOrigin || '*',
      );
    } catch {
      // Ignore cross-window communication failures.
    }
  }

  async function apiFetch(path, options) {
    if (!state.deviceToken) {
      throw createWidgetError('Device token is missing.', 401, 'device_token_missing');
    }
    const requestOptions = options || {};
    const headers = new Headers(requestOptions.headers || {});
    headers.set('Authorization', `Bearer ${state.deviceToken}`);
    if (state.parentOrigin) {
      headers.set('X-Pos-Widget-Origin', state.parentOrigin);
    }
    let body;
    if (requestOptions.body !== undefined) {
      headers.set('Content-Type', 'application/json');
      body = JSON.stringify(requestOptions.body);
    }
    const proxyBase = typeof window.__COFE_WIDGET_PROXY__ === 'string' ? window.__COFE_WIDGET_PROXY__ : '';
    const requestPath = proxyBase ? proxyBase + encodeURIComponent(path) : path;
    const response = await fetch(requestPath, {
      method: requestOptions.method || 'GET',
      headers,
      body,
      cache: 'no-store',
    });
    const payload = await parseResponsePayload(response);
    if (!response.ok) {
      throw createWidgetError(
        payload && typeof payload.error === 'string' ? payload.error : `Request failed (${response.status})`,
        response.status,
        payload && typeof payload.code === 'string' ? payload.code : null,
      );
    }
    return payload;
  }

  async function parseResponsePayload(response) {
    const contentType = response.headers.get('content-type') || '';
    if (contentType.includes('application/json')) {
      try {
        return await response.json();
      } catch {
        return {};
      }
    }
    try {
      return { error: await response.text() };
    } catch {
      return {};
    }
  }

  function createWidgetError(message, status, code) {
    const error = new Error(message);
    error.status = status;
    error.code = code || null;
    return error;
  }

  function findDraft(draftId) {
    return state.drafts.find((draft) => String(draft.id) === String(draftId)) || null;
  }

  function getUiPayload(draft) {
    return draft && draft.uiPayload && typeof draft.uiPayload === 'object' ? draft.uiPayload : {};
  }

  function draftNeedsNotes(draft) {
    const ui = getUiPayload(draft);
    if (asText(ui.notes) || asText(ui.orderNotes) || asText(ui.delivery && ui.delivery.notes)) {
      return true;
    }
    const items = Array.isArray(ui.items) ? ui.items : [];
    return items.some((item) => asText(item && item.note));
  }

  function getDraftNotes(draft) {
    const ui = getUiPayload(draft);
    const orderNotes = [];
    const itemNotes = [];
    if (asText(ui.notes)) {
      orderNotes.push({ label: t('orderNote'), text: ui.notes });
    }
    if (asText(ui.delivery && ui.delivery.notes)) {
      orderNotes.push({ label: t('deliveryNote'), text: ui.delivery.notes });
    }
    const items = Array.isArray(ui.items) ? ui.items : [];
    items.forEach((item) => {
      if (!asText(item && item.note)) return;
      itemNotes.push({
        label: formatItemLabel(item),
        note: item.note,
      });
    });
    return {
      hasNotes: orderNotes.length > 0 || itemNotes.length > 0,
      orderNotes,
      itemNotes,
    };
  }

  function renderItemLines(items, options) {
    const list = Array.isArray(items) ? items : [];
    if (!list.length) {
      return `<div class="pw-empty-copy">${escapeHtml(t('noItemsReceived'))}</div>`;
    }
    return list.map((item) => `
      <div class="pw-item">
        <div class="pw-item-main">
          <div class="pw-item-label">${escapeHtml(formatItemLabel(item))}</div>
          <div class="pw-item-qty">${escapeHtml(formatQuantity(item && item.quantity))}</div>
        </div>
        ${item && item.note && options && options.compact
          ? `<div class="pw-item-note">${escapeHtml(item.note)}</div>`
          : ''}
      </div>
    `).join('');
  }

  function summarizeItems(items) {
    const list = Array.isArray(items) ? items : [];
    if (!list.length) {
      return t('noItems');
    }
    const first = formatItemLabel(list[0]);
    if (list.length === 1) {
      return t('singleItemSummary', {
        first,
        quantity: formatQuantity(list[0] && list[0].quantity),
      });
    }
    return t('moreSummary', {
      first,
      count: formatNumber(list.length - 1),
    });
  }

  function formatItemLabel(item) {
    const name = asText(item && item.name) || t('itemFallback');
    const modifiers = Array.isArray(item && item.selectedOptions) ? item.selectedOptions : [];
    const modifierText = modifiers
      .map((option) => formatModifier(option))
      .filter(Boolean)
      .join(', ');
    return modifierText ? `${name} (${modifierText})` : name;
  }

  function formatModifier(option) {
    if (!option || typeof option !== 'object') {
      return '';
    }
    const label = asText(option.label);
    const value = asText(option.value);
    if (label && value && label.toLowerCase() !== value.toLowerCase()) {
      return `${label}: ${value}`;
    }
    return value || label || '';
  }

  function formatQuantity(value) {
    const number = Number(value);
    if (!Number.isFinite(number) || number <= 0) {
      return `×${formatNumber(1)}`;
    }
    return `×${formatNumber(number)}`;
  }

  function resolveTotal(ui) {
    const direct = Number(ui && ui.total);
    if (Number.isFinite(direct)) {
      return direct;
    }
    const summaryTotal = Number(ui && ui.summary && ui.summary.total);
    if (Number.isFinite(summaryTotal)) {
      return summaryTotal;
    }
    return 0;
  }

  function formatCurrency(value, currencyCode) {
    const safeAmount = normalizeCurrencyAmount(value, currencyCode);
    try {
      return new Intl.NumberFormat(localeTag(), {
        style: 'currency',
        currency: currencyCode || 'EGP',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      }).format(safeAmount);
    } catch {
      return `${currencyCode || 'EGP'} ${safeAmount.toFixed(2)}`;
    }
  }

  function normalizeCurrencyAmount(value, currencyCode) {
    const amount = Number(value);
    const safeAmount = Number.isFinite(amount) ? amount : 0;
    const normalizedCurrency = String(currencyCode || 'EGP').trim().toUpperCase();
    if (normalizedCurrency === 'EGP' && Number.isInteger(safeAmount) && Math.abs(safeAmount) >= 1000) {
      return safeAmount / 100;
    }
    return safeAmount;
  }

  function formatPlacedAt(value) {
    if (!value) {
      return t('justNow');
    }
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return t('justNow');
    }
    try {
      return new Intl.DateTimeFormat(localeTag(), {
        weekday: 'short',
        hour: 'numeric',
        minute: '2-digit',
      }).format(date);
    } catch {
      return date.toLocaleString();
    }
  }

  function formatDetailItemMeta(item, currencyCode) {
    const quantityLabel = formatQuantity(item && item.quantity);
    const lineTotal = Number(item && (item.lineTotalAfterDiscount ?? item.lineTotalBeforeDiscount));
    if (!Number.isFinite(lineTotal)) {
      return quantityLabel;
    }
    return `${quantityLabel} · ${formatCurrency(lineTotal, currencyCode)}`;
  }

  function getTableDisplay(tableNumber) {
    const normalized = asText(tableNumber);
    return normalized ? t('tableDisplay', { table: normalized }) : t('counterOrder');
  }

  function renderEmptyState(title, copy) {
    return `
      <div class="pw-empty-state">
        <h3 class="pw-empty-title">${escapeHtml(title)}</h3>
        <div class="pw-empty-copy">${escapeHtml(copy)}</div>
      </div>
    `;
  }

  function clampInterval(value, fallback) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) {
      return fallback;
    }
    return Math.max(1000, numeric);
  }

  function buildFallbackWebSocketUrl() {
    try {
      const nextUrl = new URL(window.location.origin);
      nextUrl.protocol = nextUrl.protocol === 'https:' ? 'wss:' : 'ws:';
      nextUrl.pathname = '/';
      nextUrl.search = '';
      nextUrl.hash = '';
      return nextUrl.toString().replace(/\/+$/g, '');
    } catch {
      return null;
    }
  }

  function showToast(message) {
    if (!elements.toast) {
      return;
    }
    if (state.toastTimer) {
      clearTimeout(state.toastTimer);
      state.toastTimer = null;
    }
    elements.toast.textContent = message || '';
    state.toastMessage = message || '';
    state.toastVisible = Boolean(message);
    elements.toast.hidden = !message;
    if (!message) {
      if (state.panelOpen) {
        renderStack();
      }
      syncParentSignals();
      return;
    }
    if (state.panelOpen) {
      renderStack();
    }
    syncParentSignals();
    state.toastTimer = window.setTimeout(() => {
      state.toastVisible = false;
      state.toastMessage = '';
      elements.toast.hidden = true;
      elements.toast.textContent = '';
      state.toastTimer = null;
      if (state.panelOpen) {
        renderStack();
      }
      syncParentSignals();
    }, TOAST_DURATION_MS);
  }

  function safeParseJson(value) {
    if (!value) return null;
    try {
      return JSON.parse(value);
    } catch {
      return null;
    }
  }

  function asText(value) {
    if (value === undefined || value === null) {
      return '';
    }
    const trimmed = String(value).trim();
    return trimmed;
  }

  function readStoredMutePreference() {
    try {
      return window.localStorage.getItem(NOTIFICATION_MUTE_STORAGE_KEY) === '1';
    } catch {
      return false;
    }
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function applyLocaleChrome() {
    const direction = isRtl() ? 'rtl' : 'ltr';
    document.documentElement.lang = state.locale;
    document.documentElement.dir = direction;
    if (elements.root) {
      elements.root.setAttribute('dir', direction);
      const displayMode = state.hostBellMode ? 'host-bell' : (state.navbarBellMode ? 'navbar-bell' : 'widget-bell');
      elements.root.dataset.displayMode = displayMode;
      elements.root.dataset.panelOpen = state.panelOpen ? 'true' : 'false';
      if (document.body) {
        document.body.dataset.displayMode = displayMode;
        document.body.dataset.panelOpen = state.panelOpen ? 'true' : 'false';
      }
    }
    if (elements.bell) {
      elements.bell.setAttribute('aria-label', t('pendingApprovals'));
      elements.bell.title = t('pendingApprovals');
    }
    if (elements.bellLabel) {
      elements.bellLabel.textContent = t('pendingApprovals');
    }
    if (elements.allOrdersKicker) elements.allOrdersKicker.textContent = t('allOrdersKicker');
    if (elements.allOrdersTitle) elements.allOrdersTitle.textContent = t('allOrdersTitle');
    if (elements.detailKicker) elements.detailKicker.textContent = t('detailKicker');
    if (elements.detailTitle) elements.detailTitle.textContent = t('detailTitle');
    if (elements.notesKicker) elements.notesKicker.textContent = t('notesKicker');
    if (elements.notesTitle) elements.notesTitle.textContent = t('notesTitle');
    if (elements.declineKicker) elements.declineKicker.textContent = t('declineReasonKicker');
    if (elements.declineTitle) elements.declineTitle.textContent = t('declineReasonTitle');
    if (elements.closeAllOrders) elements.closeAllOrders.setAttribute('aria-label', t('closeAllOrders'));
    if (elements.closeDetail) elements.closeDetail.setAttribute('aria-label', t('closeOrderDetails'));
    if (elements.closeNotes) elements.closeNotes.setAttribute('aria-label', t('closeNotes'));
    if (elements.closeDecline) elements.closeDecline.setAttribute('aria-label', t('closeDeclineReason'));
  }

  function normalizeLocale(value) {
    return asText(value).toLowerCase().startsWith('ar') ? 'ar' : 'en';
  }

  function localeTag() {
    return state.locale === 'ar' ? 'ar-EG' : 'en-EG';
  }

  function isRtl() {
    return state.locale === 'ar';
  }

  function t(key, params) {
    const messages = LOCALE_MESSAGES[state.locale] || LOCALE_MESSAGES.en;
    const fallback = LOCALE_MESSAGES.en[key];
    const value = messages[key] ?? fallback ?? key;
    return typeof value === 'function' ? value(params || {}) : value;
  }

  function formatNumber(value) {
    const numeric = Number(value);
    const safeValue = Number.isFinite(numeric) ? numeric : 0;
    try {
      return new Intl.NumberFormat(localeTag()).format(safeValue);
    } catch {
      return String(safeValue);
    }
  }

  function formatMultiline(value) {
    return escapeHtml(value || '').replace(/\n/g, '<br>');
  }
})();
