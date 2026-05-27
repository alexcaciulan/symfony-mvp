<?php

declare(strict_types=1);

namespace App\Form\Case;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form for the `emite_ordonanta` workflow transition: captures the date
 * the court issued the payment order. Must not be in the future — the
 * ruling has to have already happened. data_class null.
 */
final class IssueRulingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('rulingDate', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'label' => 'case_overview.modal.ruling_field_label',
                'constraints' => [
                    new Assert\NotBlank(message: 'case_overview.transition.ruling_date_required'),
                    new Assert\LessThanOrEqual(
                        value: 'today',
                        message: 'case_overview.transition.ruling_date_cannot_be_future',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'issue_ruling',
        ]);
    }
}
