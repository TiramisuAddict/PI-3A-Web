<?php

namespace App\Form;

use App\Entity\CompétenceEmployé;
use App\Entity\Employé;
use App\Entity\Entreprise;
use App\Entity\Participation;
use App\Entity\Projet;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\DateType;


class EmployeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom',TextType::class,['label' => 'Nom'])
            ->add('prenom',TextType::class,['label' => 'Prénom'])
            ->add('e_mail',EmailType::class,['label' => 'Email'])
            ->add('telephone',IntegerType::class,['label' => 'Téléphone'])
            ->add('poste',TextType::class,['label' => 'Poste'])
            ->add('role',TextType::class,['label' => 'Rôle'])
            ->add('date_embauche', DateType::class,['label'   => 'Date d\'embauche',
                'widget'  => 'single_text',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Employé::class,
        ]);
    }
}
