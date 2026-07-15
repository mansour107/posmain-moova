<?php
if (defined('POSMAIN_MOOVA_WIDGET_RENDERED')) {
    return;
}
define('POSMAIN_MOOVA_WIDGET_RENDERED', true);

require_once __DIR__ . '/../../classes/MoovaPosIntegration.php';

$moovaWidgetLink = null;
try {
    $moovaUserId = 0;
    if (function_exists('auth_guard_user_id_from_session')) {
        $moovaUserId = auth_guard_user_id_from_session();
    } elseif (isset($_SESSION['userid'])) {
        $moovaUserId = (int) $_SESSION['userid'];
    }
    if (isset($conn) && $conn instanceof mysqli && $moovaUserId > 0) {
        MoovaPosIntegration::ensureSchema($conn);
        $moovaWidgetLink = MoovaPosIntegration::findActiveLinkForUser($conn, $moovaUserId);
    }
} catch (Exception $e) {
    error_log('[Moova POS] widget mapping unavailable: ' . $e->getMessage());
    $moovaWidgetLink = null;
}

$moovaConnected = is_array($moovaWidgetLink) && !empty($moovaWidgetLink['moova_device_token']);
$moovaDeviceToken = $moovaConnected ? (string) $moovaWidgetLink['moova_device_token'] : '';
$moovaBranchId = $moovaConnected ? (string) $moovaWidgetLink['moova_branch_id'] : '';
$moovaLocale = $moovaConnected ? trim((string) ($moovaWidgetLink['locale'] ?: 'ar')) : 'ar';
$localWidgetUrl = 'moova_pos_widget.php';
?>
	<style>
	  .moova-navbar-widget {
	    width: 92px;
	    min-width: 92px;
	    height: 40px;
	    flex: 0 0 92px;
	    display: flex;
	    align-self: center;
	    align-items: center;
	    justify-content: center;
	    margin: 0;
	    line-height: 0;
	    overflow: visible;
	    border: 1px solid rgba(255, 255, 255, .36);
	    border-radius: 10px;
	    background: rgba(255, 255, 255, .12);
	    box-shadow: inset 0 0 0 1px rgba(15, 35, 67, .08);
	  }

	  #cofe-pos-widget {
	    width: 74px;
	    height: 38px;
	    flex: 0 0 74px;
	    border: 0;
	    background: transparent;
	    overflow: hidden;
	    display: block;
	  }

  #cofe-pos-widget.moova-widget-panel-open {
    position: fixed;
    margin: 0;
    background: transparent;
    z-index: 999999;
  }

  .moova-navbar-widget .moova-host-controls {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.15rem;
    width: 74px;
    height: 38px;
  }

  .moova-navbar-widget .pw-bell,
  .moova-navbar-widget .pw-sound-toggle {
    width: 34px;
    height: 34px;
    border: 0;
    border-radius: 50%;
    background: transparent;
    color: rgba(248, 250, 252, 0.82);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    cursor: pointer;
  }

  .moova-navbar-widget .pw-bell-icon,
  .moova-navbar-widget .pw-sound-icon {
    display: inline-flex;
    width: 20px;
    height: 20px;
  }

  .moova-navbar-widget .pw-bell-icon svg,
  .moova-navbar-widget .pw-sound-icon svg {
    width: 20px;
    height: 20px;
    fill: currentColor;
  }

  .moova-navbar-widget .pw-sound-toggle .pw-sound-icon-off {
    display: none;
  }

  .moova-navbar-widget .moova-host-controls[data-connected="false"] .pw-bell,
  .moova-navbar-widget .moova-host-controls[data-connected="false"] .pw-sound-toggle {
    opacity: 0.72;
  }
</style>

<?php if ($moovaConnected): ?>
<iframe
  id="cofe-pos-widget"
  src="<?= htmlspecialchars($localWidgetUrl, ENT_QUOTES, 'UTF-8') ?>"
  title="Cofe POS Widget"
  allowtransparency="true"
></iframe>

<script>
  (function () {
    const frame = document.getElementById('cofe-pos-widget');
    if (!frame) return;

    const WIDGET_ORIGIN = window.location.origin;
    const DEVICE_TOKEN = <?= json_encode($moovaDeviceToken) ?>;
    const MOOVA_BRANCH_ID = <?= json_encode($moovaBranchId) ?>;
    const LOCALE = <?= json_encode($moovaLocale) ?>;
    const HOST_CAPABILITIES = {
      bridgeVersion: 2,
      deliveryPath: 'widget',
      applyPath: 'direct_widget',
      orderCreation: {
        eventType: 'new_order',
        terminalStatuses: ['created', 'updated', 'declined']
      },
      orderChanges: {
        eventTypes: ['edit_order', 'cancel_order'],
        actions: ['edit', 'cancel'],
        requiresCashierConfirm: true,
        staleStateDeclineCode: 'POS_ORDER_LINES_CHANGED'
      },
      menuSync: {
        eventType: 'menu_sync',
        autoFingerprint: true,
        source: 'posmain_local_menu'
      }
    };
    const MENU_FINGERPRINT_INTERVAL_MS = 20000;
    let widgetSurfaceOpen = false;
    let widgetClosedRect = null;

    function rememberClosedWidgetRect() {
      if (!frame.classList.contains('moova-widget-panel-open')) {
        widgetClosedRect = frame.getBoundingClientRect();
      }
    }

    /**
     * Ancestors with backdrop-filter/filter/transform (e.g. .pos-corner-menu)
     * become the containing block for position:fixed descendants. Viewport
     * coordinates then misplace the iframe and the bell/speaker jump.
     * Return the padding-edge rect — that is the origin for fixed top/right.
     */
    function getFixedContainingBlockRect(el) {
      let parent = el.parentElement;
      while (parent && parent !== document.documentElement) {
        const cs = window.getComputedStyle(parent);
        const createsContainingBlock =
          (cs.transform && cs.transform !== 'none')
          || (cs.filter && cs.filter !== 'none')
          || (cs.perspective && cs.perspective !== 'none')
          || (cs.backdropFilter && cs.backdropFilter !== 'none')
          || (cs.willChange && /transform|filter|perspective|backdrop-filter/.test(cs.willChange));
        if (createsContainingBlock) {
          const rect = parent.getBoundingClientRect();
          const borderTop = Number.parseFloat(cs.borderTopWidth) || 0;
          const borderRight = Number.parseFloat(cs.borderRightWidth) || 0;
          const borderBottom = Number.parseFloat(cs.borderBottomWidth) || 0;
          const borderLeft = Number.parseFloat(cs.borderLeftWidth) || 0;
          return {
            top: rect.top + borderTop,
            left: rect.left + borderLeft,
            right: rect.right - borderRight,
            bottom: rect.bottom - borderBottom,
            width: rect.width - borderLeft - borderRight,
            height: rect.height - borderTop - borderBottom,
          };
        }
        parent = parent.parentElement;
      }
      const layoutViewportWidth = document.documentElement.clientWidth || window.innerWidth;
      const layoutViewportHeight = document.documentElement.clientHeight || window.innerHeight;
      return {
        top: 0,
        left: 0,
        right: layoutViewportWidth,
        bottom: layoutViewportHeight,
        width: layoutViewportWidth,
        height: layoutViewportHeight,
      };
    }

    function closeWidgetSurface() {
      if (!frame.contentWindow || !widgetSurfaceOpen) return;
      frame.contentWindow.postMessage({ type: 'cofe.host.close' }, WIDGET_ORIGIN);
    }

    function syncEventTypeForAction(action) {
      return action === 'cancel' ? 'cancel_order' : 'edit_order';
    }

    function bridgeMetadata(result, fallbackEventType, fallbackStatus) {
      return {
        deliveryPath: result?.deliveryPath || HOST_CAPABILITIES.deliveryPath,
        applyPath: result?.applyPath || HOST_CAPABILITIES.applyPath,
        syncEventType: result?.syncEventType || fallbackEventType,
        syncStatus: result?.syncStatus || fallbackStatus
      };
    }

    async function createOrderInSupplierPos(payload) {
      console.log('[Moova] Sending order to POS:', payload);

      const response = await fetch('ajax/moova_confirm_order.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-Moova-Device-Token': DEVICE_TOKEN,
          'Idempotency-Key': payload.idempotencyKey || ''
        },
        body: JSON.stringify(payload)
      });

      let result;
      try {
        result = await response.json();
      } catch (e) {
        throw new Error('استجابة غير صالحة من الخادم (not JSON)');
      }

      console.log('[Moova] POS response:', result);

      if (!response.ok || !result.success) {
        const err = new Error(result.message || 'POS order creation failed');
        err.code = result.code || 'POS_CREATE_FAILED';
        err.retryable = moovaIsRetryableError(err.code, response.status, result);
        err.payload = result;
        throw err;
      }

      return result;
    }

    function moovaIsRetryableError(code, status, result) {
      if (result && result.retryable === false) return false;
      const normalizedCode = String(code || '').toUpperCase();
      const nonRetryableCodes = [
        'DEVICE_TOKEN_REQUIRED',
        'UNAUTHORIZED',
        'INTEGRATION_NOT_MAPPED',
        'TENANT_SCOPE_MISMATCH',
        'INVALID_PAYLOAD',
        'TABLE_REQUIRED',
        'TABLE_NOT_FOUND',
        'ITEM_NOT_FOUND',
        'NO_VALID_ITEMS',
        'IDEMPOTENCY_PAYLOAD_CONFLICT'
      ];
      if (nonRetryableCodes.includes(normalizedCode)) return false;
      return Number(status) >= 500;
    }

    async function changeOrderInSupplierPos(payload) {
      console.log('[Moova] Sending order change to POS:', payload);

      const response = await fetch('ajax/moova_change_order.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-Moova-Device-Token': DEVICE_TOKEN,
          'Idempotency-Key': payload.idempotencyKey || ''
        },
        body: JSON.stringify(payload)
      });

      let result;
      try {
        result = await response.json();
      } catch (e) {
        throw new Error('استجابة غير صالحة من الخادم (not JSON)');
      }

      console.log('[Moova] POS change response:', result);

      if (!response.ok || !result.success) {
        const err = new Error(result.message || 'POS order change failed');
        err.code = result.code || 'POS_CHANGE_FAILED';
        err.retryable = result.retryable !== false;
        err.payload = result;
        throw err;
      }

      return result;
    }

    async function fetchMenuSyncPayload(mode) {
      const response = await fetch('ajax/moova_menu_sync_payload.php?mode=' + encodeURIComponent(mode || 'full'), {
        method: 'GET',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-Moova-Device-Token': DEVICE_TOKEN
        },
        cache: 'no-store'
      });

      let result;
      try {
        result = await response.json();
      } catch (e) {
        throw new Error('استجابة غير صالحة من الخادم (not JSON)');
      }

      if (!response.ok || !result.success) {
        const err = new Error(result.message || 'POS menu sync failed');
        err.code = result.code || 'POS_MENU_SYNC_FAILED';
        err.retryable = result.retryable !== false && response.status >= 500;
        err.payload = result;
        throw err;
      }

      return result;
    }

    async function pushMenuSyncFingerprint() {
      if (!frame.contentWindow) return;
      try {
        const result = await fetchMenuSyncPayload('fingerprint');
        frame.contentWindow.postMessage(
          {
            type: 'cofe.host.menu-fingerprint',
            catalogVersion: result.catalogVersion || result.fingerprint || null,
            fingerprint: result.fingerprint || result.catalogVersion || null,
            summary: result.summary || null
          },
          WIDGET_ORIGIN
        );
      } catch (error) {
        console.warn('[Moova] Menu fingerprint check failed:', error);
      }
    }

    async function handleMenuSyncRequested(data) {
      try {
        const payload = await fetchMenuSyncPayload('full');
        frame.contentWindow.postMessage(
          {
            type: 'cofe.host.menu-sync-result',
            ok: true,
            commandId: data.commandId,
            catalogVersion: payload.catalogVersion || payload.fingerprint || null,
            fingerprint: payload.fingerprint || payload.catalogVersion || null,
            menu: payload.menu || { categories: [], items: [] },
            rawPayload: payload.rawPayload || null,
            responsePayload: {
              source: 'posmain_local_menu',
              summary: payload.summary || null
            }
          },
          WIDGET_ORIGIN
        );
        pushMenuSyncFingerprint();
      } catch (error) {
        console.error('[Moova] Menu sync failed:', error);
        frame.contentWindow.postMessage(
          {
            type: 'cofe.host.menu-sync-result',
            ok: false,
            commandId: data.commandId,
            message: error?.message || 'POS menu sync failed',
            retryable: error?.retryable !== false,
            errorPayload: {
              code: error?.code || 'POS_MENU_SYNC_FAILED',
              payload: error?.payload || null
            }
          },
          WIDGET_ORIGIN
        );
      }
    }

    function sendCofeInit() {
      if (!frame.contentWindow) return;
      console.log('[Moova] Sending cofe.init');
      frame.contentWindow.postMessage(
        {
          type: 'cofe.init',
          deviceToken: DEVICE_TOKEN,
          locale: LOCALE,
          displayMode: 'navbar_bell',
          hostCapabilities: HOST_CAPABILITIES
        },
        WIDGET_ORIGIN
      );
      pushMenuSyncFingerprint();
    }

    frame.addEventListener('load', sendCofeInit);
    window.setInterval(pushMenuSyncFingerprint, MENU_FINGERPRINT_INTERVAL_MS);

    window.addEventListener('message', async function (event) {
      if (event.origin !== WIDGET_ORIGIN) return;
      if (event.source !== frame.contentWindow) return;

      const msgType = event.data?.type;
      console.log('[Moova] Message received:', msgType, event.data);

      if (msgType === 'cofe.widget.request-init') {
        sendCofeInit();
        return;
      }

      if (msgType === 'cofe.widget.connected') {
        pushMenuSyncFingerprint();
        return;
      }

      if (msgType === 'cofe.menu-sync.requested') {
        handleMenuSyncRequested(event.data || {});
        return;
      }

      if (msgType === 'cofe.widget.frame') {
        const requestedWidth = Number(event.data.width) || 74;
        const requestedHeight = Number(event.data.height) || 38;
        const panelOpen = event.data.mode && event.data.mode !== 'idle';
        widgetSurfaceOpen = Boolean(panelOpen);

        if (!panelOpen) {
          frame.classList.remove('moova-widget-panel-open');
          frame.style.width = '74px';
          frame.style.height = '38px';
          frame.style.background = 'transparent';
          frame.style.top = '';
          frame.style.right = '';
          frame.style.left = '';
          frame.style.bottom = '';
          window.requestAnimationFrame(rememberClosedWidgetRect);
          return;
        }

        rememberClosedWidgetRect();
        const width = Math.max(260, Math.min(window.innerWidth - 36, requestedWidth));
        const slotRect = widgetClosedRect || frame.getBoundingClientRect();
        const containingBlock = getFixedContainingBlockRect(frame);
        const top = Math.max(0, slotRect.top - containingBlock.top);
        const right = Math.max(0, containingBlock.right - slotRect.right);
        const height = Math.max(220, Math.min(window.innerHeight - slotRect.top - 18, requestedHeight));

        frame.classList.add('moova-widget-panel-open');
        frame.style.top = top + 'px';
        frame.style.right = right + 'px';
        frame.style.left = 'auto';
        frame.style.bottom = 'auto';
        frame.style.width = width + 'px';
        frame.style.height = height + 'px';
        frame.style.background = 'transparent';
        return;
      }

      if (msgType === 'cofe.order.change-requested') {
        const data = event.data;
        const cashierReviewed = data.cashierReviewed === true && data.cashierAction === 'confirm';
        if (!cashierReviewed) {
          frame.contentWindow.postMessage(
            {
              type: 'cofe.host.order-change-result',
              ok: false,
              commandId: data.commandId,
              action: data.action,
              message: 'Order change must be confirmed by the cashier.',
              retryable: true,
              deliveryPath: HOST_CAPABILITIES.deliveryPath,
              applyPath: HOST_CAPABILITIES.applyPath,
              syncEventType: syncEventTypeForAction(data.action),
              syncStatus: 'cashier_review_required',
              errorPayload: {
                code: 'CASHIER_REVIEW_REQUIRED'
              }
            },
            WIDGET_ORIGIN
          );
          return;
        }

        const payload = {
          action: data.action,
          cashierReviewed: true,
          cashierAction: 'confirm',
          moovaOrderId: data.moovaOrderId,
          idempotencyKey: data.idempotencyKey || data.requestEventId || data.commandId,
          requestEventId: data.requestEventId || null,
          providerOrderId: data.providerOrderId || null,
          providerReferenceId: data.providerReferenceId || null,
          branchId: data.branchId || MOOVA_BRANCH_ID,
          items: Array.isArray(data.items) ? data.items : []
        };

        try {
          const supplierResult = await changeOrderInSupplierPos(payload);
          const metadata = bridgeMetadata(
            supplierResult,
            syncEventTypeForAction(payload.action),
            supplierResult?.applied === false ? 'declined' : 'applied'
          );

          frame.contentWindow.postMessage(
            {
              type: 'cofe.host.order-change-result',
              ok: true,
              commandId: data.commandId,
              action: payload.action,
              applied: supplierResult?.applied === true,
              declined: supplierResult?.applied === false,
              code: supplierResult?.code || null,
              message: supplierResult?.message || null,
              providerOrderId: supplierResult?.providerOrderId || supplierResult?.orderId || payload.providerOrderId || null,
              providerReferenceId: supplierResult?.providerReferenceId || payload.idempotencyKey || null,
              providerStatus: supplierResult?.providerStatus || (supplierResult?.applied === false ? 'declined' : 'applied'),
              deliveryPath: metadata.deliveryPath,
              applyPath: metadata.applyPath,
              syncEventType: metadata.syncEventType,
              syncStatus: metadata.syncStatus,
              responsePayload: supplierResult || null
            },
            WIDGET_ORIGIN
          );
        } catch (error) {
          console.error('[Moova] Order change failed:', error);

          frame.contentWindow.postMessage(
            {
              type: 'cofe.host.order-change-result',
              ok: false,
              commandId: data.commandId,
              action: payload.action,
              message: error?.message || 'POS order change failed',
              retryable: error?.retryable !== false,
              deliveryPath: HOST_CAPABILITIES.deliveryPath,
              applyPath: HOST_CAPABILITIES.applyPath,
              syncEventType: syncEventTypeForAction(payload.action),
              syncStatus: 'failed',
              errorPayload: {
                code: error?.code || 'POS_CHANGE_FAILED',
                payload: error?.payload || payload
              }
            },
            WIDGET_ORIGIN
          );
        }
        return;
      }

      if (msgType !== 'cofe.order.confirmed') return;

      const data = event.data;
      const payload = {
        cofeOrderId: data.cofeOrderId,
        idempotencyKey: data.idempotencyKey,
        branchId: data.branchId || MOOVA_BRANCH_ID,
        tableNumber: data.tableNumber,
        items: data.items
      };
      [
        'notes',
        'orderChannel',
        'fulfillmentType',
        'externalProvider',
        'externalOrderId',
        'customerName',
        'customerPhone',
        'customerAddress',
        'deliveryZone',
        'deliveryFee',
        'deliveryStatus',
        'promisedAt'
      ].forEach(function (key) {
        if (data[key] !== undefined && data[key] !== null && data[key] !== '') {
          payload[key] = data[key];
        }
      });
      ['customer', 'delivery'].forEach(function (key) {
        if (data[key] && typeof data[key] === 'object') {
          payload[key] = data[key];
        }
      });

      try {
        const supplierResult = await createOrderInSupplierPos(payload);
        const metadata = bridgeMetadata(supplierResult, 'new_order', 'applied');

        frame.contentWindow.postMessage(
          {
            type: 'cofe.host.order-result',
            ok: true,
            draftId: data.draftId,
            providerOrderId: supplierResult?.providerOrderId || supplierResult?.orderId || null,
            providerReferenceId: supplierResult?.providerReferenceId || supplierResult?.referenceId || payload.idempotencyKey || null,
            providerStatus: supplierResult?.providerStatus || supplierResult?.status || 'created',
            deliveryPath: metadata.deliveryPath,
            applyPath: metadata.applyPath,
            syncEventType: metadata.syncEventType,
            syncStatus: metadata.syncStatus,
            responsePayload: supplierResult || null
          },
          WIDGET_ORIGIN
        );
      } catch (error) {
        console.error('[Moova] Order creation failed:', error);

        frame.contentWindow.postMessage(
          {
            type: 'cofe.host.order-result',
            ok: false,
            draftId: data.draftId,
            message: error?.message || 'POS order creation failed',
            retryable: error?.retryable !== false,
            deliveryPath: HOST_CAPABILITIES.deliveryPath,
            applyPath: HOST_CAPABILITIES.applyPath,
            syncEventType: 'new_order',
            syncStatus: 'failed',
            errorPayload: {
              code: error?.code || 'POS_CREATE_FAILED',
              payload: error?.payload || payload
            }
          },
          WIDGET_ORIGIN
        );
      }
    });

    window.addEventListener('resize', function () {
      if (!widgetSurfaceOpen) {
        widgetClosedRect = null;
        rememberClosedWidgetRect();
      }
    });

    rememberClosedWidgetRect();

    document.addEventListener('pointerdown', function (event) {
      if (!widgetSurfaceOpen) return;
      if (event.target === frame || frame.contains(event.target)) return;
      closeWidgetSurface();
    }, true);
  })();
</script>

<?php else: ?>
<div class="moova-host-controls" data-connected="false" aria-label="Moova speaker and bell">
  <button type="button" class="pw-sound-toggle" data-muted="false" aria-label="تشغيل صوت الإشعارات" title="Moova غير متصل — الصوت">
    <span class="pw-sound-icon" aria-hidden="true">
      <svg class="pw-sound-icon-on" viewBox="0 0 24 24" role="presentation">
        <path d="M4 9.5h3.2L12 5.7v12.6l-4.8-3.8H4v-5Zm10.2-.8 1.1-1.1A6.1 6.1 0 0 1 17 12c0 1.7-.7 3.3-1.8 4.4l-1.1-1.1a4.5 4.5 0 0 0 1.3-3.3c0-1.3-.5-2.5-1.2-3.3Zm2.4-2.4 1.1-1.1A9.6 9.6 0 0 1 20.5 12c0 2.6-1.1 5-2.8 6.8l-1.1-1.1A8 8 0 0 0 19 12c0-2.2-.9-4.2-2.4-5.7Z"></path>
      </svg>
      <svg class="pw-sound-icon-off" viewBox="0 0 24 24" role="presentation">
        <path d="M4 9.5h3.2L12 5.7v12.6l-4.8-3.8H4v-5Zm11.1-.6 1.2-1.2 2.2 2.2 2.2-2.2 1.2 1.2-2.2 2.2 2.2 2.2-1.2 1.2-2.2-2.2-2.2 2.2-1.2-1.2 2.2-2.2-2.2-2.2Z"></path>
      </svg>
    </span>
  </button>
  <button type="button" class="pw-bell" aria-label="الطلبات المعلّقة" title="Moova غير متصل — الجرس">
    <span class="pw-bell-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" role="presentation">
        <path d="M12 3a4 4 0 0 0-4 4v1.07a7.5 7.5 0 0 1-1.72 4.8L5 14.5V16h14v-1.5l-1.28-1.63A7.5 7.5 0 0 1 16 8.07V7a4 4 0 0 0-4-4Zm0 18a2.75 2.75 0 0 1-2.58-1.8h5.16A2.75 2.75 0 0 1 12 21Z"></path>
      </svg>
    </span>
  </button>
</div>
<?php endif; ?>

