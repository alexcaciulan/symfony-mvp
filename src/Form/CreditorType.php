<?php

declare(strict_types=1);

namespace App\Form;

use App\DTO\Wizard\Step1CreditorData;
use App\Enum\PersonType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Creditor library form (create/edit a creditor outside the wizard).
 *
 * Binds to {@see Step1CreditorData} to reuse the full validation contract
 * (per-field constraints + the conditional PF/PJ callback) without duplicating
 * it. Unlike {@see \App\Form\Wizard\Step1CreditorType} there is no autocomplete
 * path here, so the `manual` validation group is always active and the
 * `creditorEntity`/`creditorId` fields are omitted. The controller maps the DTO
 * to/from the Creditor entity (the entity's non-nullable typed setters make a
 * direct entity binding unsafe on empty submits).
 */
final class CreditorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('personType', EnumType::class, [
                'class' => PersonType::class,
                'label' => 'wizard.step1.field.person_type',
                'placeholder' => 'wizard.step1.placeholder.person_type',
                'required' => true,
                'choice_label' => fn (PersonType $t) => $t->label(),
            ])
            ->add('name', TextType::class, [
                'label' => 'wizard.step1.field.name',
                'required' => true,
            ])
            ->add('cui', TextType::class, [
                'label' => 'wizard.step1.field.cui',
                'required' => false,
                'attr' => ['data-required-on' => 'pj'],
            ])
            ->add('personalId', TextType::class, [
                'label' => 'wizard.step1.field.personal_id',
                'required' => false,
                'attr' => ['data-required-on' => 'pf'],
            ])
            ->add('onrcNumber', TextType::class, [
                'label' => 'wizard.step1.field.onrc_number',
                'required' => false,
                'attr' => ['data-required-on' => 'pj'],
            ])
            ->add('address', TextareaType::class, [
                'label' => 'wizard.step1.field.address',
                'required' => true,
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
            ->add('bankName', TextType::class, [
                'label' => 'wizard.step1.field.bank_name',
                'required' => false,
            ])
            ->add('legalRepresentative', TextType::class, [
                'label' => 'wizard.step1.field.legal_representative',
                'required' => false,
            ])
        ;

        // Mirror the wizard normalizer: strip spaces + uppercase IBAN/CUI so the
        // strict Assert\Regex accepts user-friendly input, and clear the fields
        // that don't apply to the picked personType (CUI/ONRC for PF, CNP for PJ).
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Step1CreditorData::class,
            'empty_data' => fn () => new Step1CreditorData(),
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'creditor_library',
            // No autocomplete path here, so the manual fields are always required.
            'validation_groups' => ['Default', 'manual'],
        ]);
    }
}
