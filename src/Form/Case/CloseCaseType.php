<?php

declare(strict_types=1);

namespace App\Form\Case;

use App\Enum\CloseReason;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form for closing a case from the DEFINITIVA state. The reason maps to
 * one of two workflow transitions via CloseReason::targetTransition():
 * PAID/PARTIAL → `inchide_succes`, INSOLVENT/ABANDONED → `inchide_insolvabil`.
 * data_class null.
 */
final class CloseCaseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reason', EnumType::class, [
                'class' => CloseReason::class,
                'choice_label' => fn (CloseReason $r) => $r->label(),
                'label' => 'case_overview.modal.close_case_reason',
                'placeholder' => 'case_overview.modal.close_case_reason_placeholder',
                'constraints' => [
                    new Assert\NotNull(message: 'case_overview.transition.close_reason_required'),
                ],
            ])
            ->add('details', TextareaType::class, [
                'required' => false,
                'label' => 'case_overview.modal.close_case_details_label',
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
            'csrf_token_id' => 'close_case',
        ]);
    }
}
