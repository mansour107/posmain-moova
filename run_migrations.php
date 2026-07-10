<?php

http_response_code(410);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo 'ENDPOINT_QUARANTINED: use tools/run_migrations.php from the CLI';
