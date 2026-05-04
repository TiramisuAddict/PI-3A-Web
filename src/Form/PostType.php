<?php

namespace App\Form;

use App\Entity\Post;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

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
            ->add('type_post', ChoiceType::class, [
                'label' => 'Type de publication',
                'choices' => [
                    'Annonce' => 1,
                    'Evenement' => 2,
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Publication active',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
                'label_attr' => ['class' => 'form-check-label'],
                'row_attr' => ['class' => 'mb-0 form-check form-switch'],
            ])
            ->add('date_evenement', DateType::class, [
                'label' => 'Date debut evenement',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control'],
                'row_attr' => ['class' => 'mb-3 post-form-row--event'],
            ])
            ->add('date_fin_evenement', DateType::class, [
                'label' => 'Date fin evenement',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control'],
                'row_attr' => ['class' => 'mb-3 post-form-row--event'],
            ])
            ->add('lieu', TextType::class, [
                'label' => 'Lieu',
                'required' => false,
                'attr' => ['class' => 'form-control'],
                'help' => 'Un lien Google Maps sera genere automatiquement pour les employes.',
                'row_attr' => ['class' => 'mb-3 post-form-row--event'],
            ])
            ->add('capacite_max', IntegerType::class, [
                'label' => 'Capacite maximale',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 1],
                'row_attr' => ['class' => 'mb-3 post-form-row--event'],
            ])
            ->add('event_image_files', FileType::class, [
                'label' => 'Images de l\'evenement',
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'attr' => [
                    'class' => 'form-control',
                    'accept' => '.jpg,.jpeg,.png,.webp',
                ],
                'help' => 'Vous pouvez selectionner 10 images ou plus en une seule fois.',
                'constraints' => [
                    new Assert\All([
                        'constraints' => [
                            new Assert\File([
                                'maxSize' => '5M',
                                'mimeTypes' => [
                                    'image/jpeg',
                                    'image/png',
                                    'image/webp',
                                ],
                                'mimeTypesMessage' => 'Veuillez telecharger une image valide (JPG, PNG, WebP).',
                                'maxSizeMessage' => 'Chaque image doit faire au maximum 5 MB.',
                            ]),
                        ],
                    ]),
                ],
                'row_attr' => ['class' => 'mb-3 post-form-row--event'],
            ]);

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $data = $event->getData();
            if (!\is_array($data)) {
                return;
            }

            $type = isset($data['type_post']) ? (int) $data['type_post'] : 1;
            $data['type_post'] = $type;

            $eventKeys = ['date_evenement', 'date_fin_evenement', 'lieu', 'capacite_max'];
            $dateKeys = ['date_evenement', 'date_fin_evenement'];

            if ($type !== 2) {
                foreach ($eventKeys as $key) {
                    $data[$key] = \in_array($key, $dateKeys, true) ? '' : null;
                }
                $event->setData($data);

                return;
            }

            foreach ($eventKeys as $key) {
                if (!\array_key_exists($key, $data)) {
                    $data[$key] = \in_array($key, $dateKeys, true) ? '' : null;

                    continue;
                }
                $v = $data[$key];
                if ($v === '' || $v === null) {
                    $data[$key] = \in_array($key, $dateKeys, true) ? '' : null;

                    continue;
                }
                if ($key === 'capacite_max') {
                    $data[$key] = (int) $v;
                }
            }
            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Post::class,
            'attr' => ['novalidate' => 'novalidate'],
        ]);
    }
}