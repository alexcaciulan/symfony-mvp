<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\InboundMail;
use App\Enum\InboundAuthVerdict;
use App\Enum\InboundClassification;
use App\Enum\InboundReviewStatus;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The inbound mail queue, read-only and metadata only: who, when, the class, the
 * technical sender check and the match. The text, the HTML and the raw file stay
 * with the lawyer of the case (professional secrecy), so none of them is shown or
 * linked here. An admin may only close a review; tying a message to a submission
 * needs the lawyer.
 */
class InboundMailCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogService $auditLogService,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {}

    public static function getEntityFqcn(): string
    {
        return InboundMail::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Mesaj primit')
            ->setEntityLabelInPlural('Mesaje primite')
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(30)
            ->setSearchFields(null);
    }

    public function configureActions(Actions $actions): Actions
    {
        $resolve = Action::new('resolveReview', 'Marchează rezolvat', 'fa fa-check')
            ->linkToUrl(fn (InboundMail $m): string => $this->generateUrl('admin_inbound_mail_resolve', [
                'id' => $m->getId(),
                'token' => $this->csrf->getToken('inbound_resolve_' . $m->getId())->getValue(),
            ]))
            ->displayIf(static fn (InboundMail $m): bool => $m->getReviewStatus() === InboundReviewStatus::PENDING);

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_DETAIL, $resolve)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id');
        yield TextField::new('source', 'Sursă');
        yield DateTimeField::new('receivedAt', 'Primit la');
        yield TextField::new('fromAddress', 'De la');
        yield TextField::new('toAddress', 'Către')->onlyOnDetail();
        yield TextField::new('subject', 'Subiect');
        yield TextField::new('classification', 'Clasificare')->formatValue($this->enumLabel(...))->setTemplateName('crud/field/generic');
        yield TextField::new('authVerdict', 'Verificare tehnică expeditor')->formatValue($this->enumLabel(...))->setTemplateName('crud/field/generic');
        yield TextField::new('dsnStatus', 'Cod raport livrare');
        yield TextField::new('matchMethod', 'Potrivire')->formatValue($this->enumLabel(...))->setTemplateName('crud/field/generic');
        yield TextField::new('matchConfidence', 'Încredere')->formatValue($this->enumLabel(...))->setTemplateName('crud/field/generic');
        yield TextField::new('reviewStatus', 'Verificare')->formatValue($this->enumLabel(...))->setTemplateName('crud/field/generic');
        yield TextField::new('reviewReason', 'Motiv verificare')->onlyOnDetail();
        yield IntegerField::new('legalCase.id', 'ID dosar');
        yield IntegerField::new('matchedSubmission.id', 'ID transmitere');
        yield DateTimeField::new('processedAt', 'Procesat la')->onlyOnDetail();
        yield DateTimeField::new('forwardedAt', 'Redirecționat la')->onlyOnDetail();
        yield DateTimeField::new('purgeAfter', 'Ștergere după')->onlyOnDetail();
        yield TextField::new('rawSha256', 'SHA-256')->onlyOnDetail();
        yield IntegerField::new('rawBytes', 'Octeți')->onlyOnDetail();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('classification', 'Clasificare')->setChoices($this->choices(InboundClassification::cases())))
            ->add(ChoiceFilter::new('reviewStatus', 'Verificare')->setChoices($this->choices(InboundReviewStatus::cases())))
            ->add(ChoiceFilter::new('authVerdict', 'Verificare tehnică expeditor')->setChoices($this->choices(InboundAuthVerdict::cases())));
    }

    #[Route('/admin/inbound-mail/{id}/resolve', name: 'admin_inbound_mail_resolve', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function resolve(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $mail = $this->em->getRepository(InboundMail::class)->find($id) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('inbound_resolve_' . $id, $request->query->getString('token'))) {
            throw $this->createAccessDeniedException();
        }
        if ($mail->getReviewStatus() === InboundReviewStatus::PENDING) {
            $mail->resolveReview();
            $this->auditLogService->log(
                action: 'inbound_mail_review_resolved',
                entityType: InboundMail::class,
                entityId: (string) $mail->getId(),
                newData: ['reviewReason' => $mail->getReviewReason()],
            );
            $this->em->flush();
        }

        return $this->redirect($this->adminUrlGenerator->setController(self::class)->setAction(Action::DETAIL)->setEntityId($id)->generateUrl());
    }

    /** Rendered by the generic template, which escapes it (the text template prints it raw). */
    private function enumLabel(mixed $value): string
    {
        return $value instanceof \BackedEnum && method_exists($value, 'label') ? $this->translator->trans($value->label()) : '';
    }

    /**
     * @param list<\BackedEnum> $cases
     *
     * @return array<string, string>
     */
    private function choices(array $cases): array
    {
        $choices = [];
        foreach ($cases as $case) {
            $choices[$this->translator->trans($case->label())] = (string) $case->value;
        }

        return $choices;
    }
}
