<?php

namespace App\Form;

use App\Entity\CompétenceEmployé;
use App\Entity\Employe;
use App\Entity\Entreprise;
use App\Entity\Participation;
use App\Entity\Projet;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Validator\Constraints as Assert;


class EmployeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom',TextType::class,['label' => 'Nom', 'empty_data' => '', 'constraints' => [
                    new Assert\NotBlank(message: 'Le nom est obligatoire'),
                ],])
            ->add('prenom',TextType::class,['label' => 'Prénom', 'empty_data' => '', 'constraints' => [
                    new Assert\NotBlank(message: 'Le prénom est obligatoire'),
                ],])
            ->add('e_mail',EmailType::class,['label' => 'Email', 'empty_data' => '', 'constraints' => [
                    new Assert\NotBlank(message: 'L\'email est obligatoire'),
                    new Assert\Email(message: 'L\'email n\'est pas valide'),
                ],])
            ->add('telephone',TextType::class,['label' => 'Téléphone', 'empty_data' => '', 'constraints' => [
                    new Assert\NotBlank(message: 'Le téléphone est obligatoire'),
                    new Assert\Regex(
                        pattern: '/^[0-9]+$/',
                        message: 'Le téléphone doit contenir uniquement des chiffres .'
                    ),
                ],])
            ->add('poste',TextType::class,['label' => 'Poste','constraints' => [
                    new Assert\NotBlank(message: 'Le poste est obligatoire'),
                ],])
            ->add('role', ChoiceType::class, [
                    'label'=> 'Rôle',
                    'choices'=> [
                    'RH' => 'RH',
                    'Employé'=> 'employé',
                    'Chef de projet'=> 'chef projet',
                    ],
                ],
                ['constraints' => [
                    new Assert\NotBlank(message: 'Le rôle est obligatoire'),
                ],])
            ->add('date_embauche', DateType::class,['label'   => 'Date d\'embauche',
                'widget'  => 'single_text',
                'required' => false,
                'constraints' => [
                    new Assert\LessThanOrEqual([
                        'value' => 'today',
                        'message' => 'La date d\'embauche doit être inférieure ou égale à la date d\'aujourd\'hui.',
                    ]),
                ],
            ])
            ->add('cv_data', FileType::class, [
                'label' => 'CV (PDF)',
                'mapped' => false,
                'required' => false,
                'constraints' => [
                    new Assert\File(
                        maxSize: '5M',
                        mimeTypes: ['application/pdf'],
                        mimeTypesMessage: 'Veuillez importer un fichier PDF valide.',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Employe::class,
        ]);
    }
}
