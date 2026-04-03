<?php

namespace App\Form;

use App\Entity\Employé;
use App\Entity\Projet;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProjetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom', TextType::class)
            ->add('description', TextareaType::class)
            ->add('date_debut', DateType::class, [
                'widget' => 'single_text',
            ])
            ->add('date_fin_prevue', DateType::class, [
                'widget' => 'single_text',
            ])
            ->add('date_fin_reelle', DateType::class, [
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('statut', ChoiceType::class, [
                'choices' => [
                    Projet::STATUT_PLANIFIE => Projet::STATUT_PLANIFIE,
                    Projet::STATUT_EN_COURS => Projet::STATUT_EN_COURS,
                    Projet::STATUT_EN_ATTENTE => Projet::STATUT_EN_ATTENTE,
                    Projet::STATUT_TERMINE => Projet::STATUT_TERMINE,
                    Projet::STATUT_ANNULE => Projet::STATUT_ANNULE,
                ],
            ])
            ->add('priorite', ChoiceType::class, [
                'choices' => [
                    Projet::PRIORITE_BASSE => Projet::PRIORITE_BASSE,
                    Projet::PRIORITE_MOYENNE => Projet::PRIORITE_MOYENNE,
                    Projet::PRIORITE_HAUTE => Projet::PRIORITE_HAUTE,
                    'AUCUNE' => Projet::PRIORITE_AUCUNE,
                ],
                'required' => false,
            ])
            ->add('responsable', EntityType::class, [
                'class' => Employé::class,
                'choices' => $options['responsables_choices'],
                'choice_label' => static fn (Employé $employe): string => trim(sprintf('%s %s', $employe->getNom() ?? '', $employe->getPrenom() ?? '')),
                'placeholder' => 'Choisir un responsable',
            ])
            ->add('membresEquipe', EntityType::class, [
                'class' => Employé::class,
                'choices' => $options['membres_choices'],
                'choice_label' => static fn (Employé $employe): string => trim(sprintf('%s %s', $employe->getNom() ?? '', $employe->getPrenom() ?? '')),
                'multiple' => true,
                'required' => false,
                'by_reference' => false,
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $form = $event->getForm();
            $responsable = $form->get('responsable')->getData();
            $membresEquipe = $form->get('membresEquipe')->getData();

            if ($responsable !== null && $membresEquipe !== null && $membresEquipe->contains($responsable)) {
                $form->get('membresEquipe')->addError(new FormError('Le responsable ne peut pas etre membre de son equipe projet.'));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Projet::class,
            'responsables_choices' => [],
            'membres_choices' => [],
        ]);

        $resolver->setAllowedTypes('responsables_choices', 'array');
        $resolver->setAllowedTypes('membres_choices', 'array');
    }
}
