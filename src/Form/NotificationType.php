<?php

namespace App\Form;

use App\Entity\Notification;
use App\Entity\Post;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class NotificationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('user_id', IntegerType::class, [
                'label' => 'ID utilisateur destinataire',
                'attr' => ['class' => 'form-control', 'min' => 0],
            ])
            ->add('titre', TextType::class, [
                'label' => 'Titre',
                'attr' => ['class' => 'form-control', 'maxlength' => 255],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Message',
                'attr' => ['class' => 'form-control', 'rows' => 4],
            ])
            ->add('date_creation', DateTimeType::class, [
                'label' => 'Date de création',
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('is_read', CheckboxType::class, [
                'label' => 'Lu',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
                'label_attr' => ['class' => 'form-check-label'],
            ])
            ->add('post', EntityType::class, [
                'class' => Post::class,
                'label' => 'Post lié (optionnel)',
                'required' => false,
                'choice_label' => fn (Post $p) => sprintf('#%d — %s', $p->getIdPost() ?? 0, $p->getTitre() ?? ''),
                'attr' => ['class' => 'form-select'],
                'placeholder' => '—',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Notification::class,
            'attr' => ['novalidate' => 'novalidate'],
        ]);
    }
}
