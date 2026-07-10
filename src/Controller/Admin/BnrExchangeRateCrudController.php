<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BnrExchangeRate;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;

class BnrExchangeRateCrudController extends AbstractCrudController
{
    public function __construct(
        private AuditLogService $auditLogService,
    ) {}

    public static function getEntityFqcn(): string
    {
        return BnrExchangeRate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Curs valutar BNR')
            ->setEntityLabelInPlural('Cursuri valutare BNR')
            ->setDefaultSort(['rateDate' => 'DESC'])
            ->setPaginatorPageSize(30);
    }

    public function configureActions(Actions $actions): Actions
    {
        // Rates feed the RON conversion of foreign-currency claims. Deleting a
        // historical rate would make past conversions impossible to reconstruct;
        // keep the full audited history. Import via app:import-exchange-rates.
        return $actions->disable(Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'Nr.')->onlyOnIndex();
        yield ChoiceField::new('currency', 'Monedă')
            ->setChoices(['EUR' => 'EUR', 'USD' => 'USD']);
        yield DateField::new('rateDate', 'Data cursului');
        yield NumberField::new('rate', 'Curs (RON / unitate)')
            ->setNumDecimals(4)
            ->setHelp('Curs de referință BNR (bnr.ro), RON pentru o unitate de valută. Se importă automat prin app:import-exchange-rates.');
        yield DateTimeField::new('createdAt', 'Creat la')->onlyOnDetail();
        yield DateTimeField::new('updatedAt', 'Actualizat la')->onlyOnDetail();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(DateTimeFilter::new('rateDate', 'Data cursului'));
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof BnrExchangeRate) {
            $this->auditLogService->log(
                action: 'exchange_rate_created',
                entityType: BnrExchangeRate::class,
                entityId: (string) ($entityInstance->getId() ?? 0),
                newData: $this->rateSnapshot($entityInstance),
            );
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof BnrExchangeRate) {
            $original = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance);

            $this->auditLogService->log(
                action: 'exchange_rate_updated',
                entityType: BnrExchangeRate::class,
                entityId: (string) $entityInstance->getId(),
                oldData: [
                    'currency' => $original['currency'] ?? null,
                    'rateDate' => isset($original['rateDate']) && $original['rateDate'] instanceof \DateTimeInterface
                        ? $original['rateDate']->format('Y-m-d')
                        : null,
                    'rate' => $original['rate'] ?? null,
                ],
                newData: $this->rateSnapshot($entityInstance),
            );
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    /**
     * @return array<string, string>
     */
    private function rateSnapshot(BnrExchangeRate $rate): array
    {
        return [
            'currency' => $rate->getCurrency(),
            'rateDate' => $rate->getRateDate()->format('Y-m-d'),
            'rate' => $rate->getRate(),
        ];
    }
}
