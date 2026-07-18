<?php

declare(strict_types=1);

namespace App\Form\Wizard;

use App\DTO\Wizard\Step2DebtorEntry;
use App\Enum\AnafStatus;
use App\Enum\PersonType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Wizard step 2 form — one debtor entry inside the collection. Used as the
 * `entry_type` of {@see Step2DebtorsType}, whose surrounding collection is a
 * Live Component (add/remove actions).
 *
 * The BPI attestation is collected via the unmapped `bpiVerifiedToday`
 * checkbox, bridged to the DTO's `insolvencyCheckedAt` timestamp by the SUBMIT
 * listener; it is mandatory for PJ debtors only, since BPI (Legea 85/2014) is
 * searched by CUI and the wizard collects a CUI only for PJ (see the caveat on
 * professional-individual debtors in {@see Step2DebtorEntry}).
 * The `Debtor::$inInsolvency` flag is no longer written from the wizard (a
 * lawyer who finds the debtor in BPI must not file); it stays on the admin
 * surface. The ANAF metadata fields (`anafStatus`, `anafCheckedAt`) are hidden
 * inputs the party-anaf-lookup Stimulus controller fills on an explicit sync.
 */
final class Step2DebtorEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('personType', EnumType::class, [
                'class' => PersonType::class,
                'label' => 'wizard.step2.field.person_type',
                'placeholder' => 'wizard.step2.placeholder.person_type',
                'required' => true,
                'choice_label' => fn (PersonType $t) => $t->label(),
            ])
            ->add('name', TextType::class, [
                'label' => 'wizard.step2.field.name',
                'required' => true,
            ])
            // `cui` + `onrcNumber` + `personalId` carry `data-required-on` so
            // `person-type-toggle_controller.js` can flip the HTML5 `required`
            // attribute based on the picked personType. Server-side validation
            // (DTO callback) is the authoritative gate; HTML5 is the UX layer.
            ->add('cui', TextType::class, [
                'label' => 'wizard.step2.field.cui',
                'required' => false,
                'attr' => ['data-required-on' => 'pj'],
            ])
            ->add('personalId', TextType::class, [
                'label' => 'wizard.step2.field.personal_id',
                'required' => false,
                'attr' => ['data-required-on' => 'pf'],
            ])
            ->add('onrcNumber', TextType::class, [
                'label' => 'wizard.step2.field.onrc_number',
                'required' => false,
                'attr' => ['data-required-on' => 'pj'],
            ])
            ->add('address', TextareaType::class, [
                'label' => 'wizard.step2.field.address',
                'required' => true,
            ])
            // County + locality feed CompetentCourtResolver. Auto-filled by the
            // ANAF lookup (sdenumire_Judet / sdenumire_Localitate) or AI
            // extraction; editable as manual fallback.
            ->add('addressCounty', TextType::class, [
                'label' => 'wizard.step2.field.address_county',
                'required' => false,
            ])
            ->add('addressLocality', TextType::class, [
                'label' => 'wizard.step2.field.address_locality',
                'required' => false,
            ])
            ->add('email', EmailType::class, [
                'label' => 'wizard.step2.field.email',
                'required' => false,
            ])
            ->add('phone', TextType::class, [
                'label' => 'wizard.step2.field.phone',
                'required' => false,
            ])
            ->add('iban', TextType::class, [
                'label' => 'wizard.step2.field.iban',
                'required' => false,
            ])
            ->add('administrator', TextType::class, [
                'label' => 'wizard.step2.field.administrator',
                'required' => false,
            ])
            // Virtual checkbox — the BPI attestation. Maps to the
            // `insolvencyCheckedAt` timestamp on the DTO via the SUBMIT listener
            // below; the NotNull on that property (relayed here by
            // `error_mapping`) is what blocks step 2 until it is ticked.
            ->add('bpiVerifiedToday', CheckboxType::class, [
                'mapped' => false,
                'label' => 'wizard.step2.field.bpi_verified_today',
                'required' => false,
            ])
            // ANAF metadata, populated client-side by the `party-anaf-lookup`
            // Stimulus controller when the user hits the sync button. Declared as
            // unmapped HiddenType (the DTO has typed `?AnafStatus` and
            // `?\DateTimeImmutable` properties — direct Form-to-DTO mapping
            // would need a transformer). The SUBMIT listener below converts the
            // raw string back to the typed DTO properties.
            ->add('anafStatus', HiddenType::class, [
                'mapped' => false,
                'required' => false,
            ])
            ->add('anafCheckedAt', HiddenType::class, [
                'mapped' => false,
                'required' => false,
            ])
        ;

        // PRE_SUBMIT normalizer — same UX as Step1CreditorType: accept IBAN
        // with spaces / lowercase CUI and strip+upper before strict regex.
        //
        // Also clears the fields that aren't applicable to the picked
        // personType (defense-in-depth for the `person-type-toggle` Stimulus
        // controller — if JS is disabled or DevTools tampers, stale values
        // from a previous selection won't reach the DTO).
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
                $data['administrator'] = null;
                // ANAF only applies to PJ — clear any stale lookup metadata
                // a previous PJ selection may have populated.
                $data['anafStatus'] = null;
                $data['anafCheckedAt'] = null;
                // BPI is a Legea 85/2014 (PJ) concept; a PF debtor never carries
                // the attestation, so drop a tick left over from a PJ selection.
                unset($data['bpiVerifiedToday']);
            } elseif ($personType === PersonType::PJ->value) {
                $data['personalId'] = null;
            }

            $event->setData($data);
        });

        // POST_SET_DATA — re-tick the virtual checkbox when the DTO already
        // carries an attestation. It is unmapped, so it would otherwise render
        // blank on back-navigation and the mandatory NotNull would fire against
        // a debtor the lawyer already verified.
        $builder->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
            $entry = $event->getData();
            if ($entry instanceof Step2DebtorEntry && $entry->insolvencyCheckedAt !== null) {
                $event->getForm()->get('bpiVerifiedToday')->setData(true);
            }
        });

        // SUBMIT listener — convert the virtual `bpiVerifiedToday` checkbox to
        // the real `insolvencyCheckedAt` timestamp on the DTO. Unticking clears
        // the timestamp, which the NotNull then rejects: the attestation is a
        // statement about the debtor's current BPI state, so it cannot be left
        // standing once the lawyer withdraws it.
        $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event): void {
            $entry = $event->getData();
            if (!$entry instanceof Step2DebtorEntry) {
                return;
            }
            $form = $event->getForm();

            $checkbox = $form->get('bpiVerifiedToday')->getData();
            $entry->insolvencyCheckedAt = $checkbox === true
                ? $entry->insolvencyCheckedAt ?? new \DateTimeImmutable()
                : null;

            // Map raw HTML form values (populated by the Stimulus ANAF lookup)
            // back to the typed DTO properties. Empty strings → null so a fresh
            // form load doesn't overwrite a previously-set ANAF status.
            $rawStatus = (string) ($form->get('anafStatus')->getData() ?? '');
            if ($rawStatus !== '') {
                $entry->anafStatus = AnafStatus::tryFrom($rawStatus);
            }

            $rawCheckedAt = (string) ($form->get('anafCheckedAt')->getData() ?? '');
            if ($rawCheckedAt !== '') {
                try {
                    $entry->anafCheckedAt = new \DateTimeImmutable($rawCheckedAt);
                } catch (\Exception) {
                    // Malformed timestamp from the client — leave the DTO alone.
                }
            }
        });
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $dto = $form->getData();
        if (!$dto instanceof Step2DebtorEntry) {
            return;
        }

        AutoFilledMarker::apply($view, $dto->autoFilled);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Step2DebtorEntry::class,
            'empty_data' => fn () => new Step2DebtorEntry(),
            // CSRF is owned by the wrapping Step2DebtorsType — entries are
            // children of the collection and inherit no token of their own.
            'csrf_protection' => false,
            // `insolvencyCheckedAt` has no widget of its own — surface its
            // violation on the checkbox the lawyer actually sees.
            'error_mapping' => ['insolvencyCheckedAt' => 'bpiVerifiedToday'],
        ]);
    }
}
