<?php

namespace App\Form;

use App\Entity\EventImage;
use App\Entity\Post;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EventImageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('image_path', TextType::class, [
                'label' => 'Chemin ou URL de l’image',
                'attr' => ['class' => 'form-control', 'placeholder' => 'ex. uploads/events/photo.jpg'],
            ])
            ->add('ordre', IntegerType::class, [
                'label' => 'Ordre d’affichage',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 0],
            ])
            ->add('post', EntityType::class, [
                'class' => Post::class,
                'label' => 'Post événement',
                'choice_label' => fn (Post $p) => sprintf('#%d — %s', $p->getIdPost() ?? 0, $p->getTitre() ?? ''),
                'attr' => ['class' => 'form-select'],
                'placeholder' => 'Choisir un post',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EventImage::class,
            'attr' => ['novalidate' => 'novalidate'],
        ]);
    }
}
