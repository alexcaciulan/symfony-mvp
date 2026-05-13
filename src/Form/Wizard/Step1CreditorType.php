<?php

declare(strict_types=1);

namespace App\Form\Wizard;

use App\DTO\Wizard\Step1CreditorData;
use App\Entity\Creditor;
use App\Enum\PersonType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Pas 3.1/3.3 wizard step 1 form — Creditor.
 *
 * `creditorId` (the int on the DTO) is hydrated by an unmapped autocomplete
 * field `creditorEntity` — Pas 3.3 wires {@see CreditorAutocompleteType} which
 * pulls the Creditor list scoped per-user via
 * {@see \App\Repository\CreditorRepository::createAutocompleteQueryBuilder()}.
 * A POST_SUBMIT listener bridges the Creditor entity → its id back onto the
 * DTO so the validation contract (the Expression on the DTO) stays unchanged.
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
            ->add('creditorEntity', CreditorAutocompleteType::class, [
                'mapped' => false,
                'required' => false,
            ])
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

        // PRE_SUBMIT normalizers — strip spaces + uppercase IBAN/CUI so the
        // strict-format Assert\Regex on the DTO accepts user-friendly input
        // like "RO49 RNCB 0082 0044 8001 0001" or "ro15193236".
        //
        // Also acts as defense-in-depth for the `person-type-toggle` Stimulus
        // controller: even if JS is disabled / DevTools tampers with the hidden
        // fields, we clear the fields that aren't applicable to the picked
        // personType (CUI/ONRC for PF, CNP for PJ). Symfony forms otherwise
        // try to validate stale values from a previous personType selection.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();
            if (!is_array($data)) {
                return;
            }
            if (isset($data['iban']) && is_string($data['iban'])) {
                $data['iban'] = strtoupper(preg_replace('/\s+/', '', $data['iban']) ?? '');
            }
            if (isset($data['cui']) && is_string($data['cui'])) {
                $data['cui'] = strtoupper(preg_replace('/\s+/', '', $data['cui']) ?? '');
            }

            $personType = $data['personType'] ?? null;
            if ($personType === PersonType::PF->value) {
                $data['cui'] = null;
                $data['onrcNumber'] = null;
            } elseif ($personType === PersonType::PJ->value) {
                $data['personalId'] = null;
            }

            $event->setData($data);
        });

        // POST_SUBMIT — bridge from the unmapped `creditorEntity` autocomplete
        // field to the DTO's int `creditorId`. The autocomplete emits a
        // Creditor entity (or null) — we copy its id back onto the DTO so all
        // downstream code (the Expression validator + the controller's
        // reuseOrCreateCreditor) keeps working unchanged.
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            $form = $event->getForm();
            if (!$form->has('creditorEntity')) {
                return;
            }
            $picked = $form->get('creditorEntity')->getData();
            $dto = $event->getData();
            if (!$dto instanceof Step1CreditorData) {
                return;
            }
            if ($picked instanceof Creditor) {
                $dto->creditorId = $picked->getId();
            }
            // If the user later clears the autocomplete, `creditorId` stays
            // whatever it was rendered as (the hidden field round-trips it).
            // Manual fill remains valid because the xor Expression accepts
            // either path.
        });
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
            // Tom Select (UX Autocomplete) injects auxiliary DOM inputs after
            // the first re-render — those reach the controller as fields the
            // form doesn't declare, which would otherwise trip
            // `extra_fields_message`. The DTO has strict typed properties so
            // unknown keys are dropped silently rather than persisted.
            'allow_extra_fields' => true,
        ]);
    }
}
