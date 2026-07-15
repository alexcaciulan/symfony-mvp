<?php

declare(strict_types=1);

namespace App\Form\Case;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Proof that the judicial stamp duty was paid. The payer is captured separately
 * from the file because OUG 80/2013 art. 40 alin. 3 presumes payment from a
 * transfer order signed by the DEBTOR OF THE DUTY (the claimant), so a proof in
 * someone else's name is a risk the lawyer should see before filing.
 */
final class StampDutyProofType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'label' => 'case_overview.stamp_duty.field.file',
                'mapped' => false,
                'constraints' => [
                    new Assert\NotBlank(message: 'case_overview.stamp_duty.error.file_required'),
                    new Assert\File(
                        maxSize: '10M',
                        mimeTypes: ['application/pdf', 'image/jpeg', 'image/png'],
                        mimeTypesMessage: 'document.upload.invalid_type',
                    ),
                ],
            ])
            ->add('paidAt', DateType::class, [
                'label' => 'case_overview.stamp_duty.field.paid_at',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [
                    new Assert\NotNull(message: 'case_overview.stamp_duty.error.paid_at_required'),
                    new Assert\LessThanOrEqual(
                        value: 'today',
                        message: 'case_overview.stamp_duty.error.paid_at_future',
                    ),
                ],
            ])
            ->add('paidAmount', MoneyType::class, [
                'label' => 'case_overview.stamp_duty.field.paid_amount',
                'currency' => 'RON',
                'required' => false,
                'constraints' => [
                    new Assert\Positive(message: 'case_overview.stamp_duty.error.paid_amount_positive'),
                ],
            ])
            ->add('payerName', TextType::class, [
                'label' => 'case_overview.stamp_duty.field.payer_name',
                'required' => false,
                'constraints' => [
                    new Assert\Length(max: 255),
                ],
            ])
            ->add('paymentReference', TextType::class, [
                'label' => 'case_overview.stamp_duty.field.payment_reference',
                'required' => false,
                'constraints' => [
                    new Assert\Length(max: 100),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'stamp_duty_proof',
        ]);
    }
}
