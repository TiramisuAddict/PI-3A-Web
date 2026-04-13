<?php

namespace App\Form;

use App\Entity\Commentaire;
use App\Entity\Post;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CommentaireType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('contenu', TextareaType::class, [
                'label' => 'Contenu',
                'attr' => ['class' => 'form-control', 'rows' => 4],
            ])
            ->add('date_commentaire', DateTimeType::class, [
                'label' => 'Date du commentaire',
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('utilisateur_id', IntegerType::class, [
                'label' => 'ID utilisateur',
                'attr' => ['class' => 'form-control', 'min' => 0],
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
            'data_class' => Commentaire::class,
            'attr' => ['novalidate' => 'novalidate'],
        ]);
    }
}
