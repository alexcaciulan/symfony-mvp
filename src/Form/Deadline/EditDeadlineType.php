<?php

declare(strict_types=1);

namespace App\Form\Deadline;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form for editing an existing deadline (date + description). Unlike adding, the
 * date may be in the past: the lawyer can correct a deadline that already passed.
 */
final class EditDeadlineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('deadlineDate', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'label' => 'case_overview.deadlines.modal_edit.field_date_label',
                'constraints' => [
                    new Assert\NotBlank(message: 'case_overview.deadlines.modal_edit.field_date_required'),
                ],
            ])
            ->add('description', TextType::class, [
                'required' => false,
                'label' => 'case_overview.deadlines.modal_edit.field_description_label',
                'constraints' => [
                    new Assert\Length(
                        max: 255,
                        maxMessage: 'case_overview.deadlines.modal_edit.field_description_too_long',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'edit_deadline',
        ]);
    }
}
