<?php

declare(strict_types=1);

namespace App\Form\Wizard;

use App\DTO\Wizard\Step4ConfirmationData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Pas 3.1 wizard step 4 form — Confirmare.
 *
 * Two unconditional `IsTrue` checkboxes (terms + data accuracy) plus a third
 * `acknowledgedWarnings` field with no constraint here. The Pas 3.2
 * controller turns the third checkbox into a hard requirement only when
 * `OpAdmissibilityValidator` reports WARNING-level admissibility issues,
 * via a `validation_groups` callback. The form always exposes the field so
 * the template can conditionally show it.
 */
final class Step4ConfirmationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('acceptTerms', CheckboxType::class, [
                'label' => 'wizard.step4.field.accept_terms',
                'required' => false,
            ])
            ->add('acceptDataAccuracy', CheckboxType::class, [
                'label' => 'wizard.step4.field.accept_data_accuracy',
                'required' => false,
            ])
            ->add('acknowledgedWarnings', CheckboxType::class, [
                'label' => 'wizard.step4.field.acknowledged_warnings',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Step4ConfirmationData::class,
            'empty_data' => fn () => new Step4ConfirmationData(),
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'wizard_step4_confirmation',
        ]);
    }
}
