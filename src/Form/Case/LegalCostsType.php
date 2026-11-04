<?php

declare(strict_types=1);

namespace App\Form\Case;

use App\DTO\Case\LegalCostsInput;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** The recovery costs dialog on the case page. */
class LegalCostsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('legalCostsFixed', NumberType::class, ['scale' => 2, 'html5' => true, 'required' => false])
            ->add('legalCostsCurrency', ChoiceType::class, [
                'choices' => [
                    'enum.currency.EUR' => 'EUR',
                    'enum.currency.RON' => 'RON',
                ],
                'invalid_message' => 'wizard.step3.error.invalid_currency',
                'required' => false,
            ])
            ->add('legalCostsSuccessPercent', NumberType::class, ['scale' => 2, 'html5' => true, 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LegalCostsInput::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'case_legal_costs',
            'csrf_message' => 'case.claim.edit.error.invalid_csrf',
        ]);
    }
}
