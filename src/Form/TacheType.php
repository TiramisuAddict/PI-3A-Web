<?php

namespace App\Form;

use App\Entity\Employe;
use App\Entity\Tache;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TacheType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $isEdit = $options['is_edit'] ?? false;
        $employeeSelfUpdate = $options['employee_self_update'] ?? false;
        $statusChoices = [
            'A faire' => Tache::STATUT_A_FAIRE,
            'En cours' => Tache::STATUT_EN_COURS,
            'Bloquee' => Tache::STATUT_BLOQUEE,
        ];

        if ($options['allow_completed_status']) {
            $statusChoices['Terminee'] = Tache::STATUT_TERMINEE;
        }

        $builder
            ->add('titre', TextType::class, [
                'required' => true,
                'disabled' => $employeeSelfUpdate,
                'attr' => [
                    'maxlength' => 150,
                ],
            ])
            ->add('description', TextareaType::class, [
                'required' => true,
                'disabled' => $employeeSelfUpdate,
                'attr' => [
                    'rows' => 5,
                    'maxlength' => 1000,
                ],
            ])
            ->add('priorite', ChoiceType::class, [
                'required' => true,
                'disabled' => $employeeSelfUpdate,
                'placeholder' => 'Choisir une priorite',
                'empty_data' => '',
                'choices' => [
                    'Basse' => Tache::PRIORITE_BASSE,
                    'Moyenne' => Tache::PRIORITE_MOYENNE,
                    'Haute' => Tache::PRIORITE_HAUTE,
                ],
            ])
            ->add('date_deb', DateType::class, [
                'required' => true,
                'widget' => 'single_text',
                'disabled' => $isEdit || $employeeSelfUpdate,
                'attr' => [
                    'min' => $today,
                ],
            ])
            ->add('date_limite', DateType::class, [
                'required' => true,
                'widget' => 'single_text',
                'disabled' => $employeeSelfUpdate,
                'attr' => [
                    'min' => $today,
                ],
            ])
            ->add('statut_tache', ChoiceType::class, [
                'required' => true,
                'placeholder' => 'Choisir un statut',
                'empty_data' => '',
                'choices' => $statusChoices,
            ])
            ->add('progression', IntegerType::class, [
                'required' => true,
                'attr' => [
                    'min' => 0,
                    'max' => 100,
                    'step' => 1,
                ],
            ])
            ->add('employe', EntityType::class, [
                'class' => Employe::class,
                'required' => true,
                'disabled' => $employeeSelfUpdate,
                'placeholder' => 'Choisir un employe',
                'choices' => $options['project_team_choices'],
                'choice_label' => static fn (Employe $employe): string => trim(sprintf('%s %s', $employe->getNom() ?? '', $employe->getPrenom() ?? '')),
                'property_path' => 'employe',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Tache::class,
            'project_team_choices' => [],
            'allow_completed_status' => true,
            'is_edit' => false,
            'employee_self_update' => false,
        ]);

        $resolver->setAllowedTypes('project_team_choices', 'array');
        $resolver->setAllowedTypes('allow_completed_status', 'bool');
        $resolver->setAllowedTypes('is_edit', 'bool');
        $resolver->setAllowedTypes('employee_self_update', 'bool');
    }
}
