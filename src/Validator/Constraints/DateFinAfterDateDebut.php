<?php

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

#[\Attribute]
class DateFinAfterDateDebut extends Constraint
{
    public string $message = 'La date de fin doit etre superieure ou egale a la date de debut.';
}