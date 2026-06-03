<?php

namespace App\Controller\Admin;

use App\Entity\InterestRateConfig;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;

class InterestRateConfigCrudController extends AbstractCrudController
{
    public function __construct(
        private AuditLogService $auditLogService,
    ) {}

    public static function getEntityFqcn(): string
    {
        return InterestRateConfig::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Rată dobândă')
            ->setEntityLabelInPlural('Rate dobândă')
            ->setDefaultSort(['validFrom' => 'DESC'])
            ->setPaginatorPageSize(20);
    }

    public function configureActions(Actions $actions): Actions
    {
        // The reference rate feeds InterestCalculatorService for every case.
        // Deleting a historical rate would make past interest calculations
        // impossible to reconstruct; keep the full audited history.
        return $actions->disable(Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'Nr.')->onlyOnIndex();
        yield DateField::new('validFrom', 'Valabil din');
        yield NumberField::new('referenceRate', 'Rată de referință (%)')
            ->setNumDecimals(2)
            ->setHelp('Rata de referință a BNR (bnro.ro). Modificările afectează calculul dobânzii legale (OG 13/2011) pe toate dosarele noi.');
        yield DateTimeField::new('createdAt', 'Creat la')->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Actualizat la')->onlyOnDetail();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(DateTimeFilter::new('validFrom', 'Valabil din'));
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof InterestRateConfig) {
            $this->auditLogService->log(
                action: 'interest_rate_created',
                entityType: InterestRateConfig::class,
                entityId: (string) ($entityInstance->getId() ?? 0),
                newData: $this->rateSnapshot($entityInstance),
            );
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof InterestRateConfig) {
            $original = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);

            $this->auditLogService->log(
                action: 'interest_rate_updated',
                entityType: InterestRateConfig::class,
                entityId: (string) $entityInstance->getId(),
                oldData: [
                    'validFrom' => isset($original['validFrom']) && $original['validFrom'] instanceof \DateTimeInterface
                        ? $original['validFrom']->format('Y-m-d')
                        : null,
                    'referenceRate' => $original['referenceRate'] ?? null,
                ],
                newData: $this->rateSnapshot($entityInstance),
            );
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    /**
     * @return array<string, string>
     */
    private function rateSnapshot(InterestRateConfig $config): array
    {
        return [
            'validFrom' => $config->getValidFrom()->format('Y-m-d'),
            'referenceRate' => $config->getReferenceRate(),
        ];
    }
}
