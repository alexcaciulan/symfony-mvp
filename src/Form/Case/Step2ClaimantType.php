<?php

namespace App\Form\Case;

use App\DTO\Case\Step2ClaimantData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class Step2ClaimantType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'wizard.step2.type',
                'choices' => [
                    'wizard.step2.type_pf' => 'pf',
                    'wizard.step2.type_pj' => 'pj',
                ],
                'expanded' => true,
            ])
            ->add('name', TextType::class, [
                'label' => 'wizard.step2.name',
            ])
            ->add('cnp', TextType::class, [
                'label' => 'wizard.step2.cnp',
                'required' => false,
            ])
            ->add('cui', TextType::class, [
                'label' => 'wizard.step2.cui',
                'required' => false,
            ])
            ->add('companyName', TextType::class, [
                'label' => 'wizard.step2.company_name',
                'required' => false,
            ])
            ->add('email', EmailType::class, [
                'label' => 'wizard.step2.email',
            ])
            ->add('phone', TextType::class, [
                'label' => 'wizard.step2.phone',
                'required' => false,
            ])
            ->add('street', TextType::class, [
                'label' => 'wizard.step2.street',
                'required' => false,
            ])
            ->add('streetNumber', TextType::class, [
                'label' => 'wizard.step2.street_number',
                'required' => false,
            ])
            ->add('block', TextType::class, [
                'label' => 'wizard.step2.block',
                'required' => false,
            ])
            ->add('staircase', TextType::class, [
                'label' => 'wizard.step2.staircase',
                'required' => false,
            ])
            ->add('apartment', TextType::class, [
                'label' => 'wizard.step2.apartment',
                'required' => false,
            ])
            ->add('city', TextType::class, [
                'label' => 'wizard.step2.city',
                'required' => false,
            ])
            ->add('county', TextType::class, [
                'label' => 'wizard.step2.county',
                'required' => false,
            ])
            ->add('postalCode', TextType::class, [
                'label' => 'wizard.step2.postal_code',
                'required' => false,
            ])
            ->add('hasLawyer', CheckboxType::class, [
                'label' => 'wizard.step2.has_lawyer',
                'required' => false,
            ])
            ->add('lawyerName', TextType::class, [
                'label' => 'wizard.step2.lawyer_name',
                'required' => false,
            ])
            ->add('lawyerPhone', TextType::class, [
                'label' => 'wizard.step2.lawyer_phone',
                'required' => false,
            ])
            ->add('lawyerEmail', EmailType::class, [
                'label' => 'wizard.step2.lawyer_email',
                'required' => false,
            ])
            ->add('lawyerBarNumber', TextType::class, [
                'label' => 'wizard.step2.lawyer_bar_number',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Step2ClaimantData::class,
            'validation_groups' => function (FormInterface $form) {
                $groups = ['Default'];
                $data = $form->getData();
                if ($data instanceof Step2ClaimantData && $data->type === 'pf') {
                    $groups[] = 'pf';
                }
                return $groups;
            },
        ]);
    }
}
