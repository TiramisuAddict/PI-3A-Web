<?php

namespace App\Form;

use App\Entity\Post;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire CRUD Post : ne mappe pas les associations inverses (like, participation).
 */
class PostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titre', TextType::class, [
                'label' => 'Titre',
                'attr' => ['class' => 'form-control', 'maxlength' => 255],
            ])
            ->add('contenu', TextareaType::class, [
                'label' => 'Contenu',
                'attr' => ['class' => 'form-control', 'rows' => 6],
            ])
            ->add('type_post', IntegerType::class, [
                'label' => 'Type de publication (ex. 1 = annonce, 2 = événement)',
                'attr' => ['class' => 'form-control', 'min' => 0],
            ])
            ->add('date_creation', DateTimeType::class, [
                'label' => 'Date de création',
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('utilisateur_id', IntegerType::class, [
                'label' => 'ID utilisateur (auteur)',
                'attr' => ['class' => 'form-control', 'min' => 0],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Publication active',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
                'label_attr' => ['class' => 'form-check-label'],
            ])
            ->add('date_evenement', DateType::class, [
                'label' => 'Date début événement',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('date_fin_evenement', DateType::class, [
                'label' => 'Date fin événement',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('lieu', TextType::class, [
                'label' => 'Lieu',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('capacite_max', IntegerType::class, [
                'label' => 'Capacité maximale',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 1],
            ])
            ->add('latitude', NumberType::class, [
                'label' => 'Latitude',
                'required' => false,
                'scale' => 7,
                'attr' => ['class' => 'form-control', 'step' => 'any'],
            ])
            ->add('longitude', NumberType::class, [
                'label' => 'Longitude',
                'required' => false,
                'scale' => 7,
                'attr' => ['class' => 'form-control', 'step' => 'any'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Post::class,
            'attr' => ['novalidate' => 'novalidate'],
        ]);
    }
}
