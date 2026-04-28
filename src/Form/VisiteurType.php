<?php

namespace App\Form;

use App\Entity\Visiteur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class VisiteurType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Le nom est obligatoire.',
                    ])     
            ]])
            ->add('prenom', TextType::class, [
                'label' => 'Prénom',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Le prénom est obligatoire.',
                    ]),
                               ]])
            ->add('e_mail', EmailType::class, [
                'label' => 'Email',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'L\'email est obligatoire.',
                    ]),
                    new Assert\Email([
                        'message' => 'Veuillez saisir une adresse email valide.',
                    ]),
                
            ]])
            ->add('mot_de_passe', PasswordType::class, [
                'label' => 'Mot de passe',
                'required' => true,
                'mapped' => false,
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Le mot de passe est obligatoire.',
                    ]),
                    new Assert\Length([
                        'min' => 8,
                        'max' => 255,
                        'minMessage' => 'Le mot de passe doit contenir au moins {{ limit }} caractères.',
                    ]),
                    new Assert\Regex([
                        'pattern' => '/^(?=.*[A-Za-z])(?=.*\d).+$/',
                        'message' => 'Le mot de passe doit contenir au moins une lettre et un chiffre.',
                    ]),
                ],
            ])
            ->add('telephone', TelType::class, [
                'label' => 'Téléphone',
                'required' => true,
                'attr' => [
                    'maxlength' => 8,
                    'inputmode' => 'numeric',
                    'placeholder' => '8 chiffres',
                ],
                'constraints' => [
                    new Assert\NotBlank([
                        'message' => 'Le téléphone est obligatoire.',
                    ]),
                    new Assert\Regex([
                        'pattern' => '/^\d{8}$/',
                        'message' => 'Le téléphone doit contenir exactement 8 chiffres.',
                    ]),
                ],
            ])
            ->add('save', SubmitType::class, [
                'label' => 'register',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Visiteur::class,
        ]);
    }
}