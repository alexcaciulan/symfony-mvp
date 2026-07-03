<?php

declare(strict_types=1);

namespace App\Form\Deadline;

use App\Enum\PaymentNoticeCommunicationMethod;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Date the debtor actually received the summons (CPC art. 1015 alin. 1 — the
 * 15-day term runs from receipt). The method is captured for the audit trail
 * (bailiff record vs postal receipt have different probative value).
 * LessThanOrEqual today: receipt cannot be in the future.
 */
final class PaymentNoticeCommunicationDateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('paymentNoticeCommunicationDate', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'label' => 'case_overview.summons.modal_communication_date.field_date_label',
                'constraints' => [
                    new Assert\NotBlank(message: 'case_overview.summons.modal_communication_date.field_date_required'),
                    new Assert\LessThanOrEqual(
                        value: 'today',
                        message: 'case_overview.summons.modal_communication_date.field_date_cannot_be_future',
                    ),
                ],
            ])
            ->add('paymentNoticeCommunicationMethod', EnumType::class, [
                'class' => PaymentNoticeCommunicationMethod::class,
                'choice_label' => fn (PaymentNoticeCommunicationMethod $m) => $m->label(),
                'label' => 'case_overview.summons.modal_communication_date.field_method_label',
                'placeholder' => 'case_overview.summons.modal_communication_date.field_method_placeholder',
                'constraints' => [
                    new Assert\NotNull(message: 'case_overview.summons.modal_communication_date.field_method_required'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'payment_notice_communication_date',
        ]);
    }
}
