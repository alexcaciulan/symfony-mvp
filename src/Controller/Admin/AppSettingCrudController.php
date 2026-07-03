<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AppSetting;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Runtime settings. The `einvoice_provider` row toggles the active e-invoicing
 * provider live (stub | oblio | smartbill) without a redeploy.
 */
class AppSettingCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AppSetting::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Setare')
            ->setEntityLabelInPlural('Setări aplicație')
            ->setHelp('index', 'Setează "einvoice_provider" la stub, oblio sau smartbill pentru a comuta providerul de facturare.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Cheie');
        yield TextField::new('value', 'Valoare');
    }
}
