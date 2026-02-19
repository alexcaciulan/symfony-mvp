<?php

namespace App\Form\Case;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;

class Step4ClaimType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('claimAmount', NumberType::class, [
                'label' => 'wizard.step4.claim_amount',
                'scale' => 2,
                'html5' => true,
                'attr' => ['step' => '0.01', 'min' => '0.01', 'max' => '10000'],
                'constraints' => [
                    new NotBlank(message: 'wizard.step4.amount_required'),
                    new GreaterThan(value: 0, message: 'wizard.step4.amount_positive'),
                    new LessThanOrEqual(value: 10000, message: 'wizard.step4.amount_max'),
                ],
            ])
            ->add('claimDescription', TextareaType::class, [
                'label' => 'wizard.step4.claim_description',
                'attr' => ['rows' => 5],
                'constraints' => [
                    new NotBlank(message: 'wizard.step4.description_required'),
                ],
            ])
            ->add('dueDate', DateType::class, [
                'label' => 'wizard.step4.due_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('legalBasis', TextType::class, [
                'label' => 'wizard.step4.legal_basis',
                'required' => false,
            ])
            ->add('interestType', ChoiceType::class, [
                'label' => 'wizard.step4.interest_type',
                'choices' => [
                    'wizard.step4.interest_none' => 'none',
                    'wizard.step4.interest_legal' => 'legal',
                    'wizard.step4.interest_contractual' => 'contractual',
                ],
            ])
            ->add('interestRate', NumberType::class, [
                'label' => 'wizard.step4.interest_rate',
                'scale' => 2,
                'required' => false,
                'html5' => true,
                'attr' => ['step' => '0.01'],
            ])
            ->add('interestStartDate', DateType::class, [
                'label' => 'wizard.step4.interest_start_date',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('requestCourtCosts', CheckboxType::class, [
                'label' => 'wizard.step4.request_court_costs',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
