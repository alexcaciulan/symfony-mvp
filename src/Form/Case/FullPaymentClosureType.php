<?php

declare(strict_types=1);

namespace App\Form\Case;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Closing a case on full payment, before the payment order request. The lawyer
 * states when the debtor paid and confirms nothing is left to recover. data_class null.
 */
final class FullPaymentClosureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('paymentDate', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'label' => 'case_overview.modal.full_payment_field_date_label',
                'constraints' => [
                    new Assert\NotNull(message: 'full_payment.date_required'),
                    new Assert\LessThanOrEqual(value: 'today', message: 'full_payment.date_future'),
                ],
            ])
            ->add('amountReceived', NumberType::class, [
                'required' => false,
                'input' => 'string',
                'scale' => 2,
                'label' => 'case_overview.modal.full_payment_field_amount_label',
                'constraints' => [
                    new Assert\PositiveOrZero(message: 'full_payment.amount_positive'),
                ],
            ])
            ->add('confirmFullPayment', CheckboxType::class, [
                'required' => true,
                'label' => 'case_overview.modal.full_payment_field_confirm_label',
                'constraints' => [
                    new Assert\IsTrue(message: 'full_payment.confirm_required'),
                ],
            ])
            ->add('details', TextareaType::class, [
                'required' => false,
                'label' => 'case_overview.modal.full_payment_field_details_label',
                'help' => 'case_overview.modal.full_payment_field_details_help',
                'constraints' => [
                    new Assert\Length(max: 500, maxMessage: 'full_payment.details_max'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'full_payment_closure',
        ]);
    }
}
