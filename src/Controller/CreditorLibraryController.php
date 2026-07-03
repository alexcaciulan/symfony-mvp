<?php

namespace App\Controller;

use App\DTO\Wizard\Step1CreditorData;
use App\Entity\Creditor;
use App\Entity\User;
use App\Form\CreditorType;
use App\Repository\CreditorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creditor library: the lawyer manages their reusable creditor catalog
 * (the same entities the wizard step 1 autocomplete pulls from). Create and
 * edit only; deletion is intentionally out of scope for now.
 */
#[Route('/creditors')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class CreditorLibraryController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private CreditorRepository $creditors,
        private TranslatorInterface $translator,
    ) {}

    #[Route('', name: 'app_creditors', methods: ['GET'])]
    public function index(): Response
    {
        // Rows are fetched by the Tabulator grid via the generic table endpoint
        // (see CreditorTableDefinition); the page itself only renders the shell.
        return $this->render('creditors/index.html.twig');
    }

    #[Route('/new', name: 'app_creditors_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $dto = new Step1CreditorData();
        $form = $this->createForm(CreditorType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && !$this->isDuplicateCui($form, $user, $dto, null)) {
            $creditor = new Creditor();
            $creditor->setUser($user);
            $this->applyDtoToCreditor($creditor, $dto);
            $this->em->persist($creditor);
            $this->em->flush();

            $this->addFlash('success', $this->translator->trans('library.creditors.flash.created'));

            return $this->redirectToRoute('app_creditors');
        }

        return $this->render('creditors/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_creditors_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $creditor = $this->creditors->find($id);
        if ($creditor === null) {
            throw $this->createNotFoundException();
        }
        if ($creditor->getUser()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException();
        }

        $dto = $this->dtoFromCreditor($creditor);
        $form = $this->createForm(CreditorType::class, $dto);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && !$this->isDuplicateCui($form, $user, $dto, $creditor)) {
            $this->applyDtoToCreditor($creditor, $dto);
            $this->em->flush();

            $this->addFlash('success', $this->translator->trans('library.creditors.flash.updated'));

            return $this->redirectToRoute('app_creditors');
        }

        return $this->render('creditors/edit.html.twig', [
            'form' => $form,
            'creditor' => $creditor,
        ]);
    }

    /**
     * Guard the DB-level UNIQUE(user, cui): surface a friendly form error
     * instead of letting the constraint throw on flush. Returns true (and adds
     * a violation) when another creditor of this user already uses the CUI.
     */
    private function isDuplicateCui(FormInterface $form, User $user, Step1CreditorData $dto, ?Creditor $current): bool
    {
        if ($dto->cui === null || $dto->cui === '') {
            return false;
        }

        $existing = $this->creditors->findOneBy(['user' => $user, 'cui' => $dto->cui]);
        if ($existing === null || $existing === $current) {
            return false;
        }

        $form->get('cui')->addError(new \Symfony\Component\Form\FormError(
            $this->translator->trans('library.creditors.error.cui_duplicate')
        ));

        return true;
    }

    private function dtoFromCreditor(Creditor $creditor): Step1CreditorData
    {
        $dto = new Step1CreditorData();
        $dto->personType = $creditor->getPersonType();
        $dto->name = $creditor->getName();
        $dto->cui = $creditor->getCui();
        $dto->personalId = $creditor->getPersonalId();
        $dto->onrcNumber = $creditor->getOnrcNumber();
        $dto->address = $creditor->getAddress();
        $dto->email = $creditor->getEmail();
        $dto->phone = $creditor->getPhone();
        $dto->iban = $creditor->getIban();
        $dto->bankName = $creditor->getBankName();
        $dto->legalRepresentative = $creditor->getLegalRepresentative();

        return $dto;
    }

    private function applyDtoToCreditor(Creditor $creditor, Step1CreditorData $dto): void
    {
        // The `manual` validation group guarantees personType/name/address are
        // non-null by the time we map onto the entity's typed setters.
        $creditor->setPersonType($dto->personType);
        $creditor->setName($dto->name);
        $creditor->setAddress($dto->address);
        $creditor->setCui($dto->cui);
        $creditor->setPersonalId($dto->personalId);
        $creditor->setOnrcNumber($dto->onrcNumber);
        $creditor->setEmail($dto->email);
        $creditor->setPhone($dto->phone);
        $creditor->setIban($dto->iban);
        $creditor->setBankName($dto->bankName);
        $creditor->setLegalRepresentative($dto->legalRepresentative);
    }
}
