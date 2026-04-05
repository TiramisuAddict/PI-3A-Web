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
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $isEdit = $options['is_edit'] ?? false;
        $chefProjetChoices = $options['chef_projets_choices'];

        if ($chefProjetChoices === [] && isset($options['responsables_choices']) && is_array($options['responsables_choices'])) {
            $chefProjetChoices = $options['responsables_choices'];
        }

        $builder
            ->add('nom', TextType::class, [
                'required' => true,
            ])
            ->add('description', TextareaType::class, [
                'required' => true,
            ])
            ->add('date_debut', DateType::class, [
                'widget' => 'single_text',
                'required' => true,
                'attr' => $isEdit ? [] : [
                    'min' => $today,
                    'max' => $today,
                ],
            ])
            ->add('date_fin_prevue', DateType::class, [
                'widget' => 'single_text',
                'required' => true,
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
                'required' => true,
                'placeholder' => 'Choisir un statut',
            ])
            ->add('priorite', ChoiceType::class, [
                'choices' => [
                    Projet::PRIORITE_BASSE => Projet::PRIORITE_BASSE,
                    Projet::PRIORITE_MOYENNE => Projet::PRIORITE_MOYENNE,
                    Projet::PRIORITE_HAUTE => Projet::PRIORITE_HAUTE,
                ],
                'required' => true,
                'placeholder' => 'Choisir une priorite',
            ])
            ->add('responsable', EntityType::class, [
                'class' => Employé::class,
                'choices' => $chefProjetChoices,
                'choice_label' => static fn (Employé $employe): string => trim(sprintf('%s %s', $employe->getNom() ?? '', $employe->getPrenom() ?? '')),
                'required' => true,
                'label' => 'Chef projet',
                'placeholder' => 'Choisir un chef projet',
            ])
            ->add('membresEquipe', EntityType::class, [
                'class' => Employé::class,
                'choices' => $options['membres_choices'],
                'choice_label' => static fn (Employé $employe): string => trim(sprintf('%s %s', $employe->getNom() ?? '', $employe->getPrenom() ?? '')),
                'multiple' => true,
                'expanded' => true,
                'required' => false,
                'by_reference' => false,
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $form = $event->getForm();
            $projet = $event->getData();

            if (!$projet instanceof Projet) {
                return;
            }

            if ($projet->getPriorite() === null || trim($projet->getPriorite()) === '') {
                $form->get('priorite')->addError(new FormError('Veuillez choisir une priorite.'));
            }

            if ($projet->getStatut() === null || trim($projet->getStatut()) === '') {
                $form->get('statut')->addError(new FormError('Veuillez choisir un statut.'));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Projet::class,
            'chef_projets_choices' => [],
            'responsables_choices' => [],
            'membres_choices' => [],
            'is_edit' => false,
        ]);

        $resolver->setAllowedTypes('chef_projets_choices', 'array');
        $resolver->setAllowedTypes('responsables_choices', 'array');
        $resolver->setAllowedTypes('membres_choices', 'array');
        $resolver->setAllowedTypes('is_edit', 'bool');
    }
}
