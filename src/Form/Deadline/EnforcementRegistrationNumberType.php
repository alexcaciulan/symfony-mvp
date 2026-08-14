<?php

declare(strict_types=1);

namespace App\Form\Deadline;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Registration number the bailiff assigned to the enforcement request. Recording it is
 * what closes the enforcement-limitation term (CPC art. 705 para. 1).
 *
 * The interruption itself does not wait for this number: art. 708 para. 1 pt. 2 attaches
 * it to the request as filed, on the date of the filing. Asking for the number is the
 * platform's own check, so that an irreversible closing rests on a fact confirmed from
 * outside rather than on a declaration. It is a product rule, not a legal condition.
 *
 * Free text, only bounded in length. Bailiffs number their files in formats the platform
 * has no way to validate, and rejecting a real number would leave a term open on a case
 * where the act was actually done.
 */
final class EnforcementRegistrationNumberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enforcementRegistrationNumber', TextType::class, [
                'label' => 'case_overview.deadlines.modal_enforcement_registration_number.field_label',
                'constraints' => [
                    new Assert\NotBlank(message: 'case_overview.deadlines.modal_enforcement_registration_number.field_required'),
                    new Assert\Length(
                        max: 100,
                        maxMessage: 'case_overview.deadlines.modal_enforcement_registration_number.field_too_long',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'enforcement_registration_number',
        ]);
    }
}
