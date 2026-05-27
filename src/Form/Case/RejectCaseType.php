<?php

declare(strict_types=1);

namespace App\Form\Case;

use App\Enum\RejectReason;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form for the `respinge` / `admite_cerere_anulare` workflow transitions
 * (both lead to RESPINSA status). Captures the rejection reason and
 * optional free-text details for the audit log. data_class null.
 */
final class RejectCaseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reason', EnumType::class, [
                'class' => RejectReason::class,
                'choice_label' => fn (RejectReason $r) => $r->label(),
                'label' => 'case_overview.modal.reject_reason_label',
                'placeholder' => 'case_overview.modal.reject_reason_placeholder',
                'constraints' => [
                    new Assert\NotNull(message: 'case_overview.transition.reject_reason_required'),
                ],
            ])
            ->add('details', TextareaType::class, [
                'required' => false,
                'label' => 'case_overview.modal.reject_details_label',
                'constraints' => [
                    new Assert\Length(
                        max: 500,
                        maxMessage: 'case_overview.transition.details_too_long',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'reject_case',
        ]);
    }
}
