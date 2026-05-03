<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class DemandeDetailType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $fields = is_array($options['fields'] ?? null) ? $options['fields'] : [];
        $existingDetails = is_array($options['existing_details'] ?? null) ? $options['existing_details'] : [];

        foreach ($fields as $field) {
            $key = $field['key'];
            $label = $field['label'];
            $required = true === ($field['required'] ?? false);
            $type = $field['type'] ?? 'text';
            $fieldOptions = $field['options'] ?? [];

            $constraints = [];

            if ($required) {
                $constraints[] = new Assert\NotBlank([
                    'message' => 'Le champ "' . $label . '" est obligatoire.',
                ]);
            }

            $defaultValue = $existingDetails[$key] ?? null;

            switch ($type) {
                case 'text':
                    $builder->add($key, TextType::class, [
                        'label' => $label,
                        'required' => $required,
                        'constraints' => $constraints,
                        'data' => $defaultValue,
                        'attr' => ['class' => 'form-control'],
                    ]);
                    break;

                case 'number':
                    $numberConstraints = $constraints;
                    $lowerKey = strtolower($key);
                    
                    if (
                        str_contains($lowerKey, 'montant') ||
                        str_contains($lowerKey, 'nombre') ||
                        str_contains($lowerKey, 'quantite') ||
                        str_contains($lowerKey, 'cout')
                    ) {
                        $numberConstraints[] = new Assert\Positive([
                            'message' => 'Le champ "' . $label . '" doit etre superieur a 0.',
                        ]);
                    } else {
                        $numberConstraints[] = new Assert\PositiveOrZero([
                            'message' => 'Le champ "' . $label . '" ne peut pas etre negatif.',
                        ]);
                    }

                    $builder->add($key, NumberType::class, [
                        'label' => $label,
                        'required' => $required,
                        'constraints' => $numberConstraints,
                        'data' => $defaultValue !== null ? (float) $defaultValue : null,
                        'html5' => true,
                        'attr' => ['class' => 'form-control', 'min' => '0'],
                    ]);
                    break;

                case 'date':
                    $dateConstraints = $constraints;
                    $lowerKey = strtolower($key);

                    $mustBeTodayOrFuture =
                        str_contains($lowerKey, 'datedebut') ||
                        str_contains($lowerKey, 'datesouhaitee') ||
                        str_contains($lowerKey, 'datepassage') ||
                        str_contains($lowerKey, 'dateheuressup');

                    if ($mustBeTodayOrFuture) {
                        $dateConstraints[] = new Assert\GreaterThanOrEqual([
                            'value' => 'today',
                            'message' => 'Le champ "' . $label . '" ne peut pas etre inferieur a la date actuelle.',
                        ]);
                    }

                    $dateValue = null;
                    if (isset($defaultValue) && '' !== trim((string) $defaultValue)) {
                        try {
                            $dateValue = new \DateTime((string) $defaultValue);
                        } catch (\Exception $e) {
                            $dateValue = null;
                        }
                    }

                    $builder->add($key, DateType::class, [
                        'label' => $label,
                        'required' => $required,
                        'widget' => 'single_text',
                        'constraints' => $dateConstraints,
                        'data' => $dateValue,
                        'attr' => ['class' => 'form-control'],
                    ]);
                    break;

                case 'textarea':
                    $builder->add($key, TextareaType::class, [
                        'label' => $label,
                        'required' => $required,
                        'constraints' => $constraints,
                        'data' => $defaultValue,
                        'attr' => ['class' => 'form-control', 'rows' => 3],
                    ]);
                    break;

                case 'select':
                    $choices = [];
                    foreach ($fieldOptions as $opt) {
                        $choices[$opt] = $opt;
                    }

                    $builder->add($key, ChoiceType::class, [
                        'label' => $label,
                        'required' => $required,
                        'placeholder' => '-- Selectionnez --',
                        'choices' => $choices,
                        'constraints' => $constraints,
                        'data' => $defaultValue,
                        'attr' => ['class' => 'form-select'],
                    ]);
                    break;

                case 'location':
                    $builder->add($key, TextType::class, [
                        'label' => $label,
                        'required' => $required,
                        'constraints' => $constraints,
                        'data' => $defaultValue,
                        'attr' => [
                            'class' => 'form-control location-field',
                            'readonly' => true,
                            'data-location' => 'true',
                        ],
                    ]);

                    $builder->add($key . 'Lat', HiddenType::class, [
                        'data' => $existingDetails[$key . 'Lat'] ?? null,
                        'required' => false,
                    ]);

                    $builder->add($key . 'Lon', HiddenType::class, [
                        'data' => $existingDetails[$key . 'Lon'] ?? null,
                        'required' => false,
                    ]);
                    break;
            }
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'fields' => [],
            'existing_details' => [],
        ]);
    }
}