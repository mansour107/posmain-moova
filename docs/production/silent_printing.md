# POSMAIN silent printing operations

Silent printing is a durable, unattended delivery path for customer receipts, kitchen tickets, reports, labels, and other documents. Keep `legacy` browser printing as the rollback setting until the actual printers at the shop pass acceptance.

## Runtime components

- The web application creates one durable print job per routed printer with an idempotency key.
- The print worker claims queued jobs and renders ESC/POS bytes.
- Network printers receive bytes directly over their configured address and port, normally TCP 9100.
- Cable printers receive bytes through the authenticated local print bridge and an operating-system printer queue. POSMAIN never accepts a device path or shell command from the browser.
- The bridge stores a delivery receipt before acknowledging success. A repeated delivery key replays the receipt rather than printing a second copy.

The bridge and worker are supervised services. They restart automatically after a crash or device reboot. Do not run either from a browser request.

## Installation

### macOS local installation

Run once from the POSMAIN application folder:

```sh
deploy/printing/install-macos.sh /absolute/path/to/posmain
```

The installer creates a protected bridge secret, updates the local application environment with a backup, installs two per-user launch services, and starts them. It does not configure a printer driver: install the actual receipt or kitchen printer in macOS first.

If the web application runs inside a container, install with separate app and worker addresses. For Docker Desktop on macOS, use `POSMAIN_PRINT_BRIDGE_LISTEN=0.0.0.0:17981`, `POSMAIN_PRINT_BRIDGE_APP_URL=http://host.docker.internal:17981`, and keep `POSMAIN_PRINT_BRIDGE_WORKER_URL=http://127.0.0.1:17981`. The installer writes the container-reachable URL to the application environment while the host worker keeps loopback. The HMAC secret remains mandatory; restrict port `17981` to the local machine/private container network and never expose it publicly.

### Linux and Windows

Production service templates are under `deploy/printing/systemd` and the Windows raw spool helper is under `deploy/printing/windows`. Before a customer installation, package these templates with the local POSMAIN installer and provide the PHP runtime used by the application. The service account must have access to the selected CUPS or Windows printer queues and to the POSMAIN database for the worker.

## Operator setup

Owners and managers with `printers.manage` open **الطابعات ومسارات الطلبات**.

1. Add a clear printer name and select its business role.
2. Select a real connection method: network or cable.
3. For network, enter the printer address and port. Saving the address does not claim the printer is online; the card performs a separate live check.
4. For cable, select only from printer queues discovered by the local bridge. If the list is empty, install/connect the printer in the operating system and ensure the local print service is running.
5. Select document functions. For kitchen printing, route every preparation category to at least one printer. Unrouted kitchen lines fail closed rather than disappearing.
6. Save, wait for the connectivity badge, then use **اختبار**. A successful save confirms configuration only; a successful test confirms that the current delivery path accepted the job.

“مفعلة” and “متصلة” are different facts. A printer may be enabled for routing while disconnected. The page always reports live connectivity separately and gives Arabic recovery guidance.

## Failure and duplicate-safety policy

- A connection failure before any network bytes are sent is retryable.
- If a network or cable response is lost after submission may have started, the job fails closed. The manager must inspect physical output before explicitly retrying.
- A duplicate click, repeated HTTP request, or worker replay uses the original durable job and delivery key.
- Cable submission validates the selected queue against queues reported by the operating system. No arbitrary command, file, or device path can be submitted.
- Internal diagnostic codes remain in logs and durable records. Restaurant staff see short Arabic explanations and recovery actions, never backend identifiers.

## Required shop acceptance before enabling silent mode

1. Install the exact production printer drivers and paper sizes.
2. Power-cycle the POS device, bridge, network switch, and printers; verify the services start without a manual command.
3. Print ten customer receipts and ten kitchen tickets from real browser journeys.
4. Route food and drinks to different physical printers and prove every line appears exactly once at the intended station.
5. Double-click print and prove one physical output.
6. Disconnect each network cable and USB cable, confirm the page shows disconnected, reconnect, and confirm recovery.
7. Interrupt the response after submission and prove the operator is required to inspect paper before retry.
8. Reboot during queued work and prove jobs recover without loss or duplication.
9. Confirm manager access and cashier denial for printer configuration.
10. Preserve job records, worker logs, and bridge receipts for incident reconciliation.

No software-only test can certify the final electrical, driver, cutter, code-page, cash-drawer pulse, or paper-width behavior of customer hardware. Keep browser printing available until this physical acceptance is signed off.
