<?php

declare(strict_types=1);

namespace App\Form\Deadline;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Date the ruling given on the annulment request was communicated. When the debtor
 * filed such a request, that ruling is what made the payment order final (CPC art.
 * 1024 para. 8), so the three years of enforcement limitation run from its
 * communication (CPC art. 705 para. 2), not from the lapse of the initial ten days.
 *
 * Kept apart from {@see RulingCommunicationDateType}, which records the communication
 * of the initial order: the two are different acts on different documents and
 * overwriting one with the other would move a term by months.
 *
 * LessThanOrEqual today: the communication has to have happened for the term to run.
 */
final class AnnulmentRulingCommunicationDateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('annulmentRulingCommunicationDate', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'label' => 'case_overview.deadlines.modal_annulment_ruling_date.field_label',
                'constraints' => [
                    new Assert\NotBlank(message: 'case_overview.deadlines.modal_annulment_ruling_date.field_required'),
                    new Assert\LessThanOrEqual(
                        value: 'today',
                        message: 'case_overview.deadlines.modal_annulment_ruling_date.field_cannot_be_future',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'annulment_ruling_communication_date',
        ]);
    }
}
