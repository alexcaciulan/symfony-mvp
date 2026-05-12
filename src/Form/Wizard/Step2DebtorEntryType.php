<?php

declare(strict_types=1);

namespace App\Form\Wizard;

use App\DTO\Wizard\Step2DebtorEntry;
use App\Enum\PersonType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Pas 3.1 wizard step 2 form — one debtor entry inside the collection.
 *
 * Used as the `entry_type` of {@see Step2DebtorsType}. In Pas 3.3 the
 * surrounding collection becomes a Live Component (add/remove actions);
 * this entry type stays unchanged.
 *
 * `inInsolvency` and `insolvencyCheckedAt` map the C7 BPI workflow — the
 * lawyer must tick "Verificat BPI azi" (the controller, Pas 3.2, sets
 * `insolvencyCheckedAt = now()` on tick). The ANAF metadata fields are
 * populated by the Stimulus controller in Pas 3.3 via lookup endpoint and
 * are not editable from the form; we omit them here.
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
                'required' => false,
                'choice_label' => fn (PersonType $t) => $t->label(),
            ])
            ->add('name', TextType::class, [
                'label' => 'wizard.step2.field.name',
                'required' => false,
            ])
            ->add('cui', TextType::class, [
                'label' => 'wizard.step2.field.cui',
                'required' => false,
            ])
            ->add('personalId', TextType::class, [
                'label' => 'wizard.step2.field.personal_id',
                'required' => false,
            ])
            ->add('onrcNumber', TextType::class, [
                'label' => 'wizard.step2.field.onrc_number',
                'required' => false,
            ])
            ->add('address', TextareaType::class, [
                'label' => 'wizard.step2.field.address',
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
            ->add('inInsolvency', CheckboxType::class, [
                'label' => 'wizard.step2.field.in_insolvency',
                'required' => false,
            ])
            // Virtual checkbox — "Am verificat BPI azi". Maps to a timestamp
            // on the DTO via the SUBMIT listener below. The OpAdmissibilityValidator
            // emits OP_INSOLVENCY_NOT_VERIFIED (ERROR) when `insolvencyCheckedAt`
            // is null, so the user MUST tick this for any PJ debtor to pass step 4.
            ->add('bpiVerifiedToday', CheckboxType::class, [
                'mapped' => false,
                'label' => 'wizard.step2.field.bpi_verified_today',
                'required' => false,
            ])
        ;

        // PRE_SUBMIT normalizer — same UX as Step1CreditorType: accept IBAN
        // with spaces / lowercase CUI and strip+upper before strict regex.
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
            $event->setData($data);
        });

        // SUBMIT listener — convert the virtual `bpiVerifiedToday` checkbox to
        // the real `insolvencyCheckedAt` timestamp on the DTO. We deliberately
        // never reset an existing timestamp when the box is unticked — the user
        // may have verified BPI on a previous session and we don't want a stale
        // re-render to wipe that. If they want to invalidate, they tick the
        // "Debitor în insolvență" box instead, which is the ERROR path.
        $builder->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event): void {
            $entry = $event->getData();
            if (!$entry instanceof Step2DebtorEntry) {
                return;
            }
            $checkbox = $event->getForm()->get('bpiVerifiedToday')->getData();
            if ($checkbox === true) {
                $entry->insolvencyCheckedAt = new \DateTimeImmutable();
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
        ]);
    }
}
