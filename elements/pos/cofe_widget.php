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
    width: 74px;
    height: 38px;
    flex: 0 0 74px;
    display: flex;
    align-self: center;
    align-items: center;
    justify-content: center;
    margin-inline-start: .25rem;
    margin-inline-end: .5rem;
    line-height: 0;
    overflow: visible;
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
    let widgetSurfaceOpen = false;

    function closeWidgetSurface() {
      if (!frame.contentWindow || !widgetSurfaceOpen) return;
      frame.contentWindow.postMessage({ type: 'cofe.host.close' }, WIDGET_ORIGIN);
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

    function sendCofeInit() {
      if (!frame.contentWindow) return;
      console.log('[Moova] Sending cofe.init');
      frame.contentWindow.postMessage(
        { type: 'cofe.init', deviceToken: DEVICE_TOKEN, locale: LOCALE, displayMode: 'navbar_bell' },
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
          return;
        }

        const width = Math.max(260, Math.min(window.innerWidth - 36, requestedWidth));
        const slotRect = (frame.parentElement || frame).getBoundingClientRect();
        const top = Math.max(0, Math.round(slotRect.top));
        const right = Math.max(8, Math.round(window.innerWidth - slotRect.right));
        const height = Math.max(220, Math.min(window.innerHeight - top - 18, requestedHeight));

        frame.classList.add('moova-widget-panel-open');
        frame.style.top = top + 'px';
        frame.style.right = right + 'px';
        frame.style.width = width + 'px';
        frame.style.height = height + 'px';
        frame.style.background = 'transparent';
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

        frame.contentWindow.postMessage(
          {
            type: 'cofe.host.order-result',
            ok: true,
            draftId: data.draftId,
            providerOrderId: supplierResult?.providerOrderId || supplierResult?.orderId || null,
            providerReferenceId: supplierResult?.providerReferenceId || supplierResult?.referenceId || payload.idempotencyKey || null,
            providerStatus: supplierResult?.providerStatus || supplierResult?.status || 'created',
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
            errorPayload: {
              code: error?.code || 'POS_CREATE_FAILED',
              payload: payload
            }
          },
          WIDGET_ORIGIN
        );
      }
    });

    document.addEventListener('pointerdown', function (event) {
      if (!widgetSurfaceOpen) return;
      if (event.target === frame || frame.contains(event.target)) return;
      closeWidgetSurface();
    }, true);
  })();
</script>
