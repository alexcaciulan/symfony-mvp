<?php

declare(strict_types=1);

namespace App\Form\Wizard;

use App\DTO\Wizard\Step3ClaimData;
use App\Enum\LegalGroundCategory;
use App\Enum\PenaltyType;
use App\Enum\RelationshipType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Pas 3.1 wizard step 3 form — Creanță.
 *
 * The `relationshipType` dropdown only exposes `COMERCIAL` — `CIVIL` is
 * hidden because `RelationshipType::CIVIL` throws DomainException in the
 * interest calculator (B2B-only MVP per Pas 2.1 Opțiunea a). Reintroduced
 * when B2C/P2P support lands (post-MVP).
 *
 * `legalGround` is filtered through `LegalGroundCategory::isOpEligible()`
 * — currently all 9 cases pass, but the filter makes the contract explicit
 * (any new ground excluded by future revision is auto-dropped without
 * touching this form).
 */
final class Step3ClaimType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('amount', NumberType::class, [
                'label' => 'wizard.step3.field.amount',
                'required' => true,
                'scale' => 2,
            ])
            ->add('currency', ChoiceType::class, [
                'label' => 'wizard.step3.field.currency',
                'choices' => [
                    'enum.currency.RON' => 'RON',
                    'enum.currency.EUR' => 'EUR',
                ],
                'required' => true,
            ])
            ->add('dueDate', DateType::class, [
                'label' => 'wizard.step3.field.due_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => true,
            ])
            ->add('relationshipType', EnumType::class, [
                'class' => RelationshipType::class,
                'label' => 'wizard.step3.field.relationship_type',
                'choices' => [RelationshipType::COMERCIAL],
                'choice_label' => fn (RelationshipType $t) => $t->label(),
                'required' => true,
            ])
            ->add('legalGround', EnumType::class, [
                'class' => LegalGroundCategory::class,
                'label' => 'wizard.step3.field.legal_ground',
                'placeholder' => 'wizard.step3.placeholder.legal_ground',
                'choice_filter' => fn (?LegalGroundCategory $g) => $g?->isOpEligible() ?? false,
                'choice_label' => fn (LegalGroundCategory $g) => $g->label(),
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'wizard.step3.field.description',
                'required' => false,
            ])
            ->add('penaltyType', EnumType::class, [
                'class' => PenaltyType::class,
                'label' => 'wizard.step3.field.penalty_type',
                'choice_label' => fn (PenaltyType $t) => $t->label(),
                'required' => true,
            ])
            ->add('contractualPenaltyRate', NumberType::class, [
                'label' => 'wizard.step3.field.contractual_penalty_rate',
                'help' => 'wizard.step3.help.contractual_penalty_rate',
                'scale' => 3,
                'required' => false,
            ])
            ->add('contractReference', TextType::class, [
                'label' => 'wizard.step3.field.contract_reference',
                'required' => false,
            ])
            ->add('invoiceNumber', TextType::class, [
                'label' => 'wizard.step3.field.invoice_number',
                'required' => false,
            ])
            ->add('invoiceDate', DateType::class, [
                'label' => 'wizard.step3.field.invoice_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('contractNumber', TextType::class, [
                'label' => 'wizard.step3.field.contract_number',
                'required' => false,
            ])
            ->add('contractDate', DateType::class, [
                'label' => 'wizard.step3.field.contract_date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('legalCostsFixed', NumberType::class, [
                'label' => 'wizard.step3.field.legal_costs_fixed',
                'scale' => 2,
                'required' => false,
            ])
            ->add('legalCostsCurrency', ChoiceType::class, [
                'label' => 'wizard.step3.field.legal_costs_currency',
                'choices' => [
                    'enum.currency.EUR' => 'EUR',
                    'enum.currency.RON' => 'RON',
                ],
                'required' => false,
            ])
            ->add('legalCostsSuccessPercent', NumberType::class, [
                'label' => 'wizard.step3.field.legal_costs_success_percent',
                'scale' => 2,
                'required' => false,
            ])
        ;
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $dto = $form->getData();
        if (!$dto instanceof Step3ClaimData) {
            return;
        }

        AutoFilledMarker::apply($view, $dto->autoFilled);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Step3ClaimData::class,
            'empty_data' => fn () => new Step3ClaimData(),
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'wizard_step3_claim',
        ]);
    }
}
