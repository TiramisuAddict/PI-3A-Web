<?php

namespace App\Form;

use App\Entity\LikePost;
use App\Entity\Post;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LikePostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('utilisateur_id', IntegerType::class, [
                'label' => 'ID utilisateur',
                'attr' => ['class' => 'form-control', 'min' => 0],
            ])
            ->add('date_like', DateTimeType::class, [
                'label' => 'Date du like',
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('post', EntityType::class, [
                'class' => Post::class,
                'label' => 'Publication',
                'choice_label' => fn (Post $p) => sprintf('#%d — %s', $p->getIdPost() ?? 0, $p->getTitre() ?? ''),
                'attr' => ['class' => 'form-select'],
                'placeholder' => 'Choisir un post',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LikePost::class,
            'attr' => ['novalidate' => 'novalidate'],
        ]);
    }
}
