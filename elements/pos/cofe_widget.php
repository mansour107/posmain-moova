<?php
require_once __DIR__ . '/../../classes/MoovaPosIntegration.php';

$moovaWidgetLink = null;
try {
    if (isset($conn) && $conn instanceof mysqli && isset($_SESSION['userid'])) {
        MoovaPosIntegration::ensureSchema($conn);
        $moovaWidgetLink = MoovaPosIntegration::findActiveLinkForUser($conn, (int) $_SESSION['userid']);
    }
} catch (Exception $e) {
    error_log('[Moova POS] widget mapping unavailable: ' . $e->getMessage());
    $moovaWidgetLink = null;
}

if (!$moovaWidgetLink || empty($moovaWidgetLink['moova_device_token'])) {
    return;
}

$moovaDeviceToken = (string) $moovaWidgetLink['moova_device_token'];
$moovaBranchId = (string) $moovaWidgetLink['moova_branch_id'];
$moovaLocale = trim((string) ($moovaWidgetLink['locale'] ?: 'ar'));
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
    background: transparent;
    z-index: 999999;
  }
</style>

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
      }
    };
    let widgetSurfaceOpen = false;
    let widgetClosedRect = null;

    function rememberClosedWidgetRect() {
      if (!frame.classList.contains('moova-widget-panel-open')) {
        widgetClosedRect = frame.getBoundingClientRect();
      }
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
        throw err;
      }

      return result;
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
    }

    frame.addEventListener('load', sendCofeInit);

    window.addEventListener('message', async function (event) {
      if (event.origin !== WIDGET_ORIGIN) return;
      if (event.source !== frame.contentWindow) return;

      const msgType = event.data?.type;
      console.log('[Moova] Message received:', msgType, event.data);

      if (msgType === 'cofe.widget.request-init') {
        sendCofeInit();
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
          window.requestAnimationFrame(rememberClosedWidgetRect);
          return;
        }

        rememberClosedWidgetRect();
        const width = Math.max(260, Math.min(window.innerWidth - 36, requestedWidth));
        const slotRect = widgetClosedRect || frame.getBoundingClientRect();
        const layoutViewportWidth = document.documentElement.clientWidth || window.innerWidth;
        const top = Math.max(0, Math.round(slotRect.top));
        const right = Math.max(8, Math.round(layoutViewportWidth - slotRect.right));
        const height = Math.max(220, Math.min(window.innerHeight - top - 18, requestedHeight));

        frame.classList.add('moova-widget-panel-open');
        frame.style.top = top + 'px';
        frame.style.right = right + 'px';
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
            retryable: true,
            deliveryPath: HOST_CAPABILITIES.deliveryPath,
            applyPath: HOST_CAPABILITIES.applyPath,
            syncEventType: 'new_order',
            syncStatus: 'failed',
            errorPayload: {
              code: error?.code || 'POS_CREATE_FAILED',
              payload: payload
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
