<?php

require_once __DIR__ . '/../../../config/app_config.php';

class PosRequest
{
    private array $payload;
    private array $server;

    public function __construct(array $payload, array $server = [])
    {
        $this->payload = $payload;
        $this->server = $server ?: $_SERVER;
    }

    public static function fromGlobals(): self
    {
        $raw = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            $decoded = $_POST;
        }

        return new self($decoded, $_SERVER);
    }

    public function payload(): array
    {
        return $this->payload;
    }

    public function server(): array
    {
        return $this->server;
    }

    public function userId(): int
    {
        if (function_exists('current_user_id')) {
            $userId = (int) current_user_id();
            if ($userId > 0) {
                return $userId;
            }
        }

        return (int) ($_SESSION['userid'] ?? $_SESSION['user_id'] ?? 0);
    }
}
