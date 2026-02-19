<?php

namespace App\Form\Case;

use App\DTO\Case\Step5WitnessEntry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class WitnessEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'wizard.step5.witness_name',
            ])
            ->add('address', TextType::class, [
                'label' => 'wizard.step5.witness_address',
                'required' => false,
            ])
            ->add('details', TextareaType::class, [
                'label' => 'wizard.step5.witness_details',
                'required' => false,
                'attr' => ['rows' => 2],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Step5WitnessEntry::class,
        ]);
    }
}
