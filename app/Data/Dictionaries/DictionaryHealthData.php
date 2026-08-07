<?php

namespace App\Data\Dictionaries;

final class DictionaryHealthData
{
    public function __construct(
        public readonly string $status,
        public readonly string $code,
        public readonly string $message,
        public readonly bool $queryAvailable,
        public readonly bool $repairRequired,
    ) {
    }

    /** @return array{status: string, code: string, message: string, query_available: bool, repair_required: bool} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'code' => $this->code,
            'message' => $this->message,
            'query_available' => $this->queryAvailable,
            'repair_required' => $this->repairRequired,
        ];
    }

    public function canCountRecords(): bool
    {
        return in_array($this->status, ['healthy', 'disabled'], true);
    }
}
