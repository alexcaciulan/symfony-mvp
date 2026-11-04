<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\DTO\Wizard\Step2DebtorsData;
use App\Form\Wizard\Step2DebtorsType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\LiveCollectionTrait;

/**
 * Interactive multi-debtor collection for wizard step 2.
 *
 * Replaces the earlier non-JS "Adaugă debitor" handler of the debtor step
 * (`_action=add_debtor`). The component
 * uses {@see LiveCollectionTrait} which exposes ready-made `addCollectionItem`
 * and `removeCollectionItem` LiveActions wired to the form's collection field
 * names. Pre-rendered button data attributes (`data-live-action-param="addCollectionItem"`
 * + `data-live-name-param="step2_debtors[debtors]"`) trigger the right mutation.
 *
 * The component sits INSIDE the wizard page; the outer page renders the page
 * shell + nav bar. The component's `<form>` posts to `case_wizard_debtor` so
 * Step2DebtorController can still capture the final submission. LiveActions
 * mutate `$this->formValues` (the canonical live state from
 * ComponentWithFormTrait, hydrated/dehydrated automatically): partial input
 * is preserved across add/remove, unlike the earlier server round-trip.
 *
 * Product cap is 5 entries (matches `Step2DebtorsData::$debtors` Assert\Count
 * max). Min 1 is enforced at submit-time by the same constraint; the template
 * hides the per-row "remove" button when only one entry is visible.
 */
#[AsLiveComponent(name: 'Step2DebtorsLiveComponent')]
final class Step2DebtorsLiveComponent extends AbstractController
{
    use DefaultActionTrait;
    use LiveCollectionTrait;

    public ?Step2DebtorsData $initialFormData = null;

    /** The cap the form enforces, owned by the DTO the form binds to. */
    public const MAX_DEBTORS = Step2DebtorsData::MAX_DEBTORS;

    protected function instantiateForm(): FormInterface
    {
        return $this->createForm(Step2DebtorsType::class, $this->initialFormData);
    }
}
