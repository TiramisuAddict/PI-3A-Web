<?php

namespace App\Form;

use App\Entity\EventImage;
use App\Entity\Post;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class EventImageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('image_path', FileType::class, [
                'label' => 'Image de l\'événement',
                'mapped' => false,
                'required' => false,
                'attr' => ['class' => 'form-control'],
                'help' => 'Formats acceptés: JPG, PNG, WebP. Taille maximale: 5MB.',
                'constraints' => [
                    new Assert\File([
                        'maxSize' => '5M',
                        'mimeTypes' => [
                            'image/jpeg',
                            'image/png',
                            'image/webp',
                        ],
                        'mimeTypesMessage' => 'Veuillez télécharger une image valide (JPG, PNG, WebP).',
                        'maxSizeMessage' => 'Le fichier dépasse la taille maximale de 5MB.',
                    ]),
                ],
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
