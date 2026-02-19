<?php

namespace App\Form\Case;

use App\DTO\Case\Step3DefendantEntry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class DefendantEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'wizard.step3.type',
                'choices' => [
                    'wizard.step3.type_pf' => 'pf',
                    'wizard.step3.type_pj' => 'pj',
                ],
                'expanded' => true,
            ])
            ->add('name', TextType::class, [
                'label' => 'wizard.step3.name',
            ])
            ->add('cnp', TextType::class, [
                'label' => 'wizard.step3.cnp',
                'required' => false,
            ])
            ->add('cui', TextType::class, [
                'label' => 'wizard.step3.cui',
                'required' => false,
            ])
            ->add('companyName', TextType::class, [
                'label' => 'wizard.step3.company_name',
                'required' => false,
            ])
            ->add('street', TextType::class, [
                'label' => 'wizard.step3.street',
                'required' => false,
            ])
            ->add('streetNumber', TextType::class, [
                'label' => 'wizard.step3.street_number',
                'required' => false,
            ])
            ->add('block', TextType::class, [
                'label' => 'wizard.step3.block',
                'required' => false,
            ])
            ->add('staircase', TextType::class, [
                'label' => 'wizard.step3.staircase',
                'required' => false,
            ])
            ->add('apartment', TextType::class, [
                'label' => 'wizard.step3.apartment',
                'required' => false,
            ])
            ->add('city', TextType::class, [
                'label' => 'wizard.step3.city',
            ])
            ->add('county', TextType::class, [
                'label' => 'wizard.step3.county',
            ])
            ->add('postalCode', TextType::class, [
                'label' => 'wizard.step3.postal_code',
                'required' => false,
            ])
            ->add('email', EmailType::class, [
                'label' => 'wizard.step3.email',
                'required' => false,
            ])
            ->add('phone', TextType::class, [
                'label' => 'wizard.step3.phone',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Step3DefendantEntry::class,
        ]);
    }
}
