<?php

class RecipeMovementResult
{
    public $success;
    public $noop;
    public $movementIds;
    public $reservationIds;
    public $warnings;

    public function __construct(array $data = [])
    {
        $this->success = (bool) ($data['success'] ?? true);
        $this->noop = (bool) ($data['noop'] ?? false);
        $this->movementIds = $data['movement_ids'] ?? [];
        $this->reservationIds = $data['reservation_ids'] ?? [];
        $this->warnings = $data['warnings'] ?? [];
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'noop' => $this->noop,
            'movement_ids' => $this->movementIds,
            'reservation_ids' => $this->reservationIds,
            'warnings' => $this->warnings,
        ];
    }
}
