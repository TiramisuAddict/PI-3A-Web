<?php

namespace App\Form;

use App\Entity\Offre;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;

use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class OffreType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder

            ->add('titre_poste' , TextType::class, [
                'label' => 'Titre du poste',
                'attr' => ['placeholder' => 'Entrez le titre du poste'],
                'required' => false,
            ])

            ->add('type_contrat', ChoiceType::class, [
                'label' => 'Type de contrat',
                'choices' => [
                    'CDI' => 'CDI',
                    'CDD' => 'CDD',
                    'STAGE' => 'STAGE',
                    'CVP' => 'CVP',
                ],
                'required' => false,
            ])

            ->add('date_limite', DateType::class, [
                'label' => 'Date limite',
                'widget' => 'single_text',
                'required' => false,
            ])

            ->add('etat', ChoiceType::class, [
                'label' => 'État',
                'choices' => [
                    'Ouvert' => 'Ouvert',
                    'Fermé' => 'Fermé',
                ],
                'required' => false,
            ])

            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'attr' => ['placeholder' => 'Entrez la description de l\'offre'],
                'required' => false,
            ])

            ->add('categorie', ChoiceType::class, [
                'label' => 'Catégorie',
                'choices' => [
                    'Informatique' => 'Informatique',
                    'Marketing' => 'Marketing',
                    'Vente' => 'Vente',
                    'Finance' => 'Finance',
                    'Ressources Humaines' => 'Ressources Humaines',
                    'Santé' => 'Santé',
                    'Education' => 'Education',
                    'Art et Design' => 'Art et Design',
                    'Autre' => 'Autre',
                ],
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
            'data_class' => Offre::class,
        ]);
    }
}
