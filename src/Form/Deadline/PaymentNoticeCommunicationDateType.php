<?php

declare(strict_types=1);

namespace App\Form\Deadline;

use App\Enum\PaymentNoticeCommunicationMethod;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Date the debtor actually received the summons (CPC art. 1015 para. 1: the 15-day
 * term runs from receipt). The method is captured for the audit trail, a bailiff
 * record and a postal receipt having different probative value.
 * LessThanOrEqual today: receipt cannot be in the future.
 *
 * The proof itself is collected here too, optionally, so that the postal case closes
 * in one step; see the field below for why it is not required.
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
            ])
            // Optional on purpose, and that is the whole point of collecting it here.
            // Served by the post office, the lawyer finds the acknowledgement in his
            // mailbox and learns the date from it, so both arrive together and one trip
            // through this dialog closes the matter. Served by a bailiff, the date is
            // known before the record is issued, so requiring the file would hold back
            // the date and with it the 15-day term of CPC art. 1015 para. 1.
            ->add('communicationProof', FileType::class, [
                'label' => 'case_overview.summons.modal_communication_date.field_proof_label',
                'required' => false,
                'constraints' => [
                    new Assert\File(
                        maxSize: '10M',
                        mimeTypes: ['application/pdf', 'image/jpeg', 'image/png'],
                        mimeTypesMessage: 'document.upload.invalid_type',
                    ),
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
