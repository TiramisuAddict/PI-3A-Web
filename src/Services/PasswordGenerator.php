<?php

namespace App\Services;

class PasswordGenerator
{
    public function generate(int $length = 10): string
    {
        return substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!'), 0, $length);
    }
}