<?php

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class DateFinAfterDateDebutValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$value instanceof \DateTimeInterface) {
            return;
        }

        if (!$constraint instanceof DateFinAfterDateDebut) {
            return;
        }

        $form = $this->context->getRoot();
        $data = $form->getData();

        if (!is_array($data)) {
            return;
        }

        $startDateKeys = ['dateDebut', 'dateDebutTeletravail', 'dateDebutHoraires', 'dateDebutFormation'];
        $startDate = null;

        foreach ($startDateKeys as $key) {
            if (isset($data[$key]) && $data[$key] instanceof \DateTimeInterface) {
                $startDate = $data[$key];
                break;
            }
        }

        if (null !== $startDate && $value < $startDate) {
            $this->context->buildViolation($constraint->message)
                ->addViolation();
        }
    }
}