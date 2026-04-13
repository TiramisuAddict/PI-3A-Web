<?php

namespace App\Form;

use App\Entity\Participation;
use App\Entity\Post;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ParticipationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('statut', TextType::class, [
                'label' => 'Statut (ex. GOING, DECLINED)',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('date_action', DateTimeType::class, [
                'label' => 'Date d’action',
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('post', EntityType::class, [
                'class' => Post::class,
                'label' => 'Événement / post',
                'choice_label' => fn (Post $p) => sprintf('#%d — %s', $p->getIdPost() ?? 0, $p->getTitre() ?? ''),
                'attr' => ['class' => 'form-select'],
                'placeholder' => 'Choisir un post',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Participation::class,
            'attr' => ['novalidate' => 'novalidate'],
        ]);
    }
}
