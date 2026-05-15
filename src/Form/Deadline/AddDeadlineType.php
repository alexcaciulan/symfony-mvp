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
 * Pas 4.3 — Form pentru adăugare termen custom JUDECATA (ședință viitoare la
 * instanță). Tipul e fix la JUDECATA în controller — formularul cere doar data
 * + descriere opțională (ex: „Sala C2, complet C12, ora 11:00").
 */
final class AddDeadlineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('deadlineDate', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'label' => 'case_overview.deadlines.modal_add.field_date_label',
                'constraints' => [
                    new Assert\NotBlank(message: 'case_overview.deadlines.modal_add.field_date_required'),
                    new Assert\GreaterThanOrEqual(
                        value: 'today',
                        message: 'case_overview.deadlines.modal_add.field_date_must_be_future',
                    ),
                ],
            ])
            ->add('description', TextType::class, [
                'required' => false,
                'label' => 'case_overview.deadlines.modal_add.field_description_label',
                'constraints' => [
                    new Assert\Length(
                        max: 255,
                        maxMessage: 'case_overview.deadlines.modal_add.field_description_too_long',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'add_deadline',
        ]);
    }
}
