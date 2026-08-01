<?php

// Compatibility entrypoint retained for existing CI manifests. The production
// transport proof uses real network/cable configuration shapes and no test-only
// transport exposed to the product.
require __DIR__ . '/printing_production_bridge_test.php';
require __DIR__ . '/silent_printing_production_integration_test.php';
