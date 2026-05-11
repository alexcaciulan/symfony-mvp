<?php

declare(strict_types=1);

namespace App\Form\Wizard;

use App\DTO\Wizard\Step1CreditorData;
use App\Enum\PersonType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Pas 3.1 wizard step 1 form — Creditor.
 *
 * `creditorId` is exposed here as a plain `IntegerType` placeholder so the
 * controller can already round-trip the xor logic ("pick existing vs fill
 * manually"). Pas 3.3 swaps it for an `EntityType` with UX Autocomplete
 * (`createAutocompleteQueryBuilder(User)` scoping) — but the validation
 * contract (the Expression on the DTO) stays unchanged.
 *
 * `data-auto-filled="true"` is applied in {@see finishView()} to every field
 * whose name appears in the DTO's `$autoFilled` list. Stimulus (Pas 3.3)
 * reads the attribute to render the "auto · N%" badge.
 */
final class Step1CreditorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('creditorId', IntegerType::class, [
                'label' => false,
                'required' => false,
            ])
            ->add('personType', EnumType::class, [
                'class' => PersonType::class,
                'label' => 'wizard.step1.field.person_type',
                'placeholder' => 'wizard.step1.placeholder.person_type',
                'required' => false,
                'choice_label' => fn (PersonType $t) => $t->label(),
            ])
            ->add('name', TextType::class, [
                'label' => 'wizard.step1.field.name',
                'required' => false,
            ])
            ->add('cui', TextType::class, [
                'label' => 'wizard.step1.field.cui',
                'required' => false,
            ])
            ->add('personalId', TextType::class, [
                'label' => 'wizard.step1.field.personal_id',
                'required' => false,
            ])
            ->add('onrcNumber', TextType::class, [
                'label' => 'wizard.step1.field.onrc_number',
                'required' => false,
            ])
            ->add('address', TextareaType::class, [
                'label' => 'wizard.step1.field.address',
                'required' => false,
            ])
            ->add('email', EmailType::class, [
                'label' => 'wizard.step1.field.email',
                'required' => false,
            ])
            ->add('phone', TextType::class, [
                'label' => 'wizard.step1.field.phone',
                'required' => false,
            ])
            ->add('iban', TextType::class, [
                'label' => 'wizard.step1.field.iban',
                'required' => false,
            ])
            ->add('legalRepresentative', TextType::class, [
                'label' => 'wizard.step1.field.legal_representative',
                'required' => false,
            ])
        ;
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $dto = $form->getData();
        if (!$dto instanceof Step1CreditorData) {
            return;
        }

        AutoFilledMarker::apply($view, $dto->autoFilled);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Step1CreditorData::class,
            'empty_data' => fn () => new Step1CreditorData(),
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'wizard_step1_creditor',
        ]);
    }
}
