<?php

namespace App\Form;

use App\Entity\Demande;
use App\Entity\Employe;
use App\Services\DemandeFormHelper;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class DemandeType extends AbstractType
{
    private DemandeFormHelper $formHelper;

    public function __construct(DemandeFormHelper $formHelper)
    {
        $this->formHelper = $formHelper;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = $options['is_edit'];
        $includeEmploye = $options['include_employe'];
        $employeChoices = $options['employe_choices'];
        $statusChoicesOption = $options['status_choices'];
        $categories = $this->formHelper->getCategoryTypes();
        $priorites = $this->formHelper->getPriorites();
        $statuses = $this->formHelper->getStatuses();

        $categoryChoices = [];
        foreach (array_keys($categories) as $cat) {
            $categoryChoices[$cat] = $cat;
        }

        $prioriteChoices = [];
        foreach ($priorites as $p) {
            $prioriteChoices[$p] = $p;
        }

        $statusChoices = [];
        foreach ($statuses as $s) {
            $statusChoices[$s] = $s;
        }

        if (!$isEdit && $includeEmploye) {
            $builder->add('employe', EntityType::class, [
                'class' => Employe::class,
                'choices' => $employeChoices,
                'choice_label' => function (Employe $employe) {
                    return $employe->getNom() . ' ' . $employe->getPrenom();
                },
                'placeholder' => '-- Choisir un employe --',
                'label' => 'Employe',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(['message' => 'L\'employe est obligatoire.']),
                ],
            ]);
        }

        $builder
            ->add('categorie', ChoiceType::class, [
                'choices' => $categoryChoices,
                'placeholder' => '-- Choisir une categorie --',
                'label' => 'Categorie',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(['message' => 'La categorie est obligatoire.']),
                ],
            ])
            ->add('titre', TextType::class, [
                'label' => 'Titre',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Le titre est obligatoire.']),
                    new Assert\Length([
                        'min' => 3,
                        'max' => 255,
                        'minMessage' => 'Le titre doit contenir au moins {{ limit }} caracteres.',
                        'maxMessage' => 'Le titre ne peut pas depasser {{ limit }} caracteres.',
                    ]),
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
            ])
            ->add('priorite', ChoiceType::class, [
                'choices' => $prioriteChoices,
                'label' => 'Priorite',
                'required' => true,
                'data' => $isEdit ? null : 'NORMALE',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'La priorite est obligatoire.']),
                ],
            ]);

        if ($isEdit) {
            $statusChoices = [];
            $sourceStatuses = !empty($statusChoicesOption) ? $statusChoicesOption : $statuses;
            foreach ($sourceStatuses as $status) {
                $statusChoices[$status] = $status;
            }

            $builder->add('status', ChoiceType::class, [
                'choices' => $statusChoices,
                'label' => 'Statut',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Le statut est obligatoire.']),
                ],
            ]);

            $builder->add('commentaire', TextareaType::class, [
                'label' => 'Commentaire de modification',
                'required' => false,
                'mapped' => false,
            ]);
        }

        $formModifier = function (FormInterface $form, ?string $categorie) {
            $types = [];
            if ($categorie) {
                $categoryTypes = $this->formHelper->getCategoryTypes();
                if (isset($categoryTypes[$categorie])) {
                    foreach ($categoryTypes[$categorie] as $type) {
                        $types[$type] = $type;
                    }
                }
            }

            $form->add('typeDemande', ChoiceType::class, [
                'choices' => $types,
                'placeholder' => empty($types) ? '-- Choisir d\'abord une categorie --' : '-- Choisir un type --',
                'label' => 'Type de demande',
                'required' => true,
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Le type de demande est obligatoire.']),
                ],
            ]);
        };

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($formModifier) {
            $data = $event->getData();
            $categorie = $data ? $data->getCategorie() : null;
            $formModifier($event->getForm(), $categorie);
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event) use ($formModifier) {
            $data = $event->getData();
            $categorie = $data['categorie'] ?? null;
            $formModifier($event->getForm(), $categorie);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Demande::class,
            'is_edit' => false,
            'include_employe' => true,
            'employe_choices' => [],
            'status_choices' => [],
        ]);
    }
}
