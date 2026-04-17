<?php

namespace App\Form;

use App\Entity\Candidat;
use App\Entity\Offre;
use App\Entity\Visiteur;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class CandidatType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('etat' , ChoiceType::class, [
                'label' => 'État de la candidature',
                'choices' => [
                    'En attente' => 'En attente',
                    'Acceptée' => 'Acceptée',
                    'Refusée' => 'Refusé',
                    'Entretien' => 'Entretien',
                    'Présélectionné' => 'Présélectionné',
                ],
                'required' => true,
            ])
            ->add('note' , TextAreaType::class, [
                'label' => 'Note du recruteur',
                'attr' => ['placeholder' => 'Entrez une note ou un commentaire sur le candidat'],
                'required' => false,
            ])

            ->add('submit', SubmitType::class, [
                'label' => 'Enregistrer',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Candidat::class,
        ]);
    }
}
