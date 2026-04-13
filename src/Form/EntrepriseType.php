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
            ->add('nom_entreprise')
            ->add('pays')
            ->add('ville')
            ->add('nom')
            ->add('prenom')
            ->add('matricule_fiscale')
            ->add('telephone', TelType::class, [
                'label' => 'Téléphone',
                'attr'  => ['placeholder' => 'Téléphone'],
                'constraints' => [
                    new Assert\Regex(
                        pattern: '/^[0-9]+$/',
                        message: 'Le téléphone doit contenir uniquement des chiffres .'
                    ),
                ],
            ])
            ->add('e_mail', EmailType::class, [
                'label' => 'Email',
                'attr'  => ['placeholder' => 'Email'],
                'constraints' => [
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
