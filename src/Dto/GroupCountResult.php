<?php

namespace App\Dto;

final class GroupCountResult
{
    public function __construct(
        public readonly mixed $label,
        public readonly mixed $count,
    ) {
    }

    public function getCount(): int
    {
        return (int) $this->count;
    }
}