<?php

declare(strict_types=1);

namespace App\Form\Deadline;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Pas 4.3 — Form pentru data comunicării ordonanței către debitor.
 * Conform CPC art. 1024 alin. 1, termenul de 10 zile pentru cererea în anulare
 * curge de la această dată. Avocatul o populează când are dovada comunicării
 * (proces-verbal executor / recipisă R+CD+AR de la Poșta Română).
 *
 * Constraint LessThanOrEqual today: data nu poate fi în viitor (comunicarea
 * trebuie să se fi întâmplat deja pentru ca termenul să curgă).
 */
final class RulingCommunicationDateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('rulingCommunicationDate', DateType::class, [
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'label' => 'case_overview.deadlines.modal_ruling_date.field_label',
                'constraints' => [
                    new Assert\NotBlank(message: 'case_overview.deadlines.modal_ruling_date.field_required'),
                    new Assert\LessThanOrEqual(
                        value: 'today',
                        message: 'case_overview.deadlines.modal_ruling_date.field_cannot_be_future',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'ruling_communication_date',
        ]);
    }
}
