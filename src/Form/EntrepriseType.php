<?php

namespace App\Form;

use App\Entity\Entreprise;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Validator\Constraints\File;

class EntrepriseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom_entreprise', TextType::class, [
                'label' => 'Nom de l\'entreprise',
                'constraints' => [
                    new Assert\NotBlank(message: 'Le nom de l\'entreprise est obligatoire.'),
                ],
            ])
            ->add('pays', TextType::class, [
                'constraints' => [
                    new Assert\NotBlank(message: 'Le pays est obligatoire.'),
                ],
            ])
            ->add('ville', TextType::class, [
                'constraints' => [
                    new Assert\NotBlank(message: 'La ville est obligatoire.'),
                ],
            ])
            ->add('nom', TextType::class, [
                'constraints' => [
                    new Assert\NotBlank(message: 'Le nom est obligatoire.'),
                ],
            ])
            ->add('prenom', TextType::class, [
                'constraints' => [
                    new Assert\NotBlank(message: 'Le prénom est obligatoire.'),
                ],
            ])
            ->add('matricule_fiscale', TextType::class, [
                'constraints' => [
                    new Assert\NotBlank(message: 'Le matricule fiscale est obligatoire.'),
                ],
            ])
            ->add('telephone', TelType::class, [
                'label' => 'Téléphone',
                'attr'  => ['placeholder' => 'Téléphone'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Le téléphone est obligatoire.'),
                    new Assert\Regex(
                        pattern: '/^[0-9]{8}$/',
                        message: 'Le téléphone doit contenir exactement 8 chiffres.'
                    ),
                ],
            ])
            ->add('e_mail', EmailType::class, [
                'label' => 'Email',
                'attr'  => ['placeholder' => 'Email'],
                'constraints' => [
                    new Assert\NotBlank(message: 'L\'email est obligatoire.'),
                    new Assert\Email(message: 'Format email invalide'),
                ],
            ])
            ->add('site_web', UrlType::class, [
                'required' => false,
            ])
            ->add('logo', FileType::class, [
            'label' => 'Logo',
            'mapped' => false, 
            'required' => false,
            'constraints' => [
                new File([
                    'maxSize' => '2M',
                    'mimeTypes' => [
                        'image/jpeg',
                        'image/png',
                        'image/webp',
                    ],
                    'mimeTypesMessage' => 'Veuillez uploader une image valide',
                ])
            ],
        ])
            ->add('Soumettre', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Entreprise::class,
        ]);
    }
}
