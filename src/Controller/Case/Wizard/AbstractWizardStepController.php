<?php

declare(strict_types=1);

namespace App\Controller\Case\Wizard;

use App\DTO\Wizard\ConflictSettlement;
use App\Service\Wizard\WizardBag;
use App\Service\Wizard\WizardStateStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What the case creation wizard's step controllers share.
 *
 * Routes are mounted at /case/new by each step controller (attributes are
 * not inherited, and this class carries none). The cross-step state is a
 * `WizardBag` loaded from and saved to the `WizardStateStore` on every
 * action: `documentIds` (set in step 0), `creditor` (Step1CreditorData),
 * `debtors` (Step2DebtorsData), `claim` (Step3ClaimData), plus the positions
 * table and the decisions taken on the disagreements between documents.
 */
abstract class AbstractWizardStepController extends AbstractController
{
    public function __construct(
        protected readonly WizardStateStore $stateStore,
        protected readonly TranslatorInterface $translator,
    ) {}

    /**
     * The response for a step that refuses to advance.
     *
     * A submission held back by an unacknowledged conflict is well formed and
     * still unprocessable, which is the same status Symfony gives a re-rendered
     * invalid form. Sharing it keeps every rejected wizard POST answering the
     * same way, so Turbo renders the panel instead of taking a 200 for a step
     * the lawyer never completed.
     */
    protected function stepRejected(bool $acknowledged): ?Response
    {
        return $acknowledged ? null : new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Where the step cannot advance past the documents' disagreements, says
     * why where the lawyer will see it.
     */
    protected function settled(ConflictSettlement $settlement): bool
    {
        if ($settlement->flashKey !== null) {
            $this->addFlash('error', $settlement->flashKey);
        }

        return $settlement->settled;
    }

    /**
     * Sends a run whose saved debtors the product no longer accepts back to
     * the debtor step, saying why.
     */
    protected function redirectForDebtorsNoLongerOffered(WizardBag $bag): ?Response
    {
        $reason = $bag->debtorsNoLongerOfferedReason();
        if ($reason === null) {
            return null;
        }
        $this->addFlash('warning', $reason);

        return $this->redirectToRoute('case_wizard_debtor');
    }

    /**
     * Translation helper for flash messages that need parameter interpolation.
     * `addFlash()` only stores the raw key, so the message is rendered here and
     * stored already translated.
     *
     * @param array<string, string> $params
     */
    protected function trans(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params);
    }
}
