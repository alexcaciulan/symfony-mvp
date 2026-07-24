<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Document;
use App\Enum\DocumentType;
use App\Enum\ExtractionFailureReason;
use App\Enum\ExtractionStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Read-only back-office view of uploaded documents. Surfaces the extraction
 * lifecycle (detected type, status, failure reason) and the content hash used
 * for dedup, which are otherwise invisible outside the wizard.
 */
class DocumentCrudController extends AbstractCrudController
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {}

    public static function getEntityFqcn(): string
    {
        return Document::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Document')
            ->setEntityLabelInPlural('Documente')
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(30)
            ->setSearchFields(['originalFilename', 'contentHash']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->disable(Action::NEW, Action::EDIT, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id', 'Nr.')->onlyOnIndex();
        yield AssociationField::new('legalCase', 'Dosar');
        yield TextField::new('originalFilename', 'Fișier');
        yield ChoiceField::new('documentType', 'Tip declarat')
            ->setChoices($this->enumChoices(DocumentType::cases()));
        yield ChoiceField::new('detectedType', 'Tip detectat')
            ->setChoices($this->enumChoices(DocumentType::cases()));
        yield ChoiceField::new('extractionStatus', 'Stare extracție')
            ->setChoices($this->enumChoices(ExtractionStatus::cases()));
        yield ChoiceField::new('extractionFailureReason', 'Motiv eșec')
            ->setChoices($this->enumChoices(ExtractionFailureReason::cases()));
        yield NumberField::new('extractionConfidence', 'Încredere')
            ->onlyOnDetail();
        yield TextField::new('extractionStrategy', 'Strategie')->onlyOnDetail();
        yield TextField::new('detectedTypeConfidence', 'Încredere tip detectat')->onlyOnDetail();
        yield TextField::new('mimeType', 'MIME')->onlyOnDetail();
        yield IntegerField::new('fileSize', 'Mărime (octeți)')->onlyOnDetail();
        yield TextField::new('contentHash', 'Hash conținut')->onlyOnDetail();
        yield AssociationField::new('uploadedBy', 'Încărcat de')->onlyOnDetail();
        yield DateTimeField::new('createdAt', 'Creat la');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('extractionStatus', 'Stare extracție')
                ->setChoices($this->enumChoices(ExtractionStatus::cases())))
            ->add(ChoiceFilter::new('detectedType', 'Tip detectat')
                ->setChoices($this->enumChoices(DocumentType::cases())))
            ->add(ChoiceFilter::new('extractionFailureReason', 'Motiv eșec')
                ->setChoices($this->enumChoices(ExtractionFailureReason::cases())))
            ->add(EntityFilter::new('legalCase', 'Dosar'));
    }

    /**
     * @param list<DocumentType|ExtractionStatus|ExtractionFailureReason> $cases
     *
     * @return array<string, string> translated label => value (EasyAdmin format)
     */
    private function enumChoices(array $cases): array
    {
        $choices = [];
        foreach ($cases as $case) {
            $choices[$this->translator->trans($case->label())] = $case->value;
        }
        return $choices;
    }
}
