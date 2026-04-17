<?php

namespace App\Services;

class PasswordGenerator
{
    public function generatePlain(int $length = 10): string
    {
        return substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!'), 0, $length);
    }

    public function generate(int $length = 10): string
    {
        return $this->generatePlain($length);
    }
}