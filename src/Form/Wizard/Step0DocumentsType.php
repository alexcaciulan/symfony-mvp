<?php

declare(strict_types=1);

namespace App\Form\Wizard;

use App\Service\Document\DocumentUploadService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\File;

/**
 * Pas 3.0 wizard step 0 form — multi-file upload.
 *
 * The form's only data field is `documents`. We keep it unmapped so the
 * controller can iterate the uploaded files itself, feeding each one into
 * DocumentUploadService and dispatching ExtractDataMessage. The form validates
 * (1) per-file MIME against the whitelist defined on DocumentUploadService —
 * single source of truth so the extraction cascade and the upload form can't
 * drift — and (2) total file count.
 *
 * Max file size is enforced both via Symfony's File constraint (10 MB) and
 * via the HTML `accept` attribute as a UX hint. The actual rejection still
 * happens server-side after MIME sniffing in DocumentUploadService.
 */
final class Step0DocumentsType extends AbstractType
{
    public const MAX_FILES = 10;
    public const MAX_FILE_SIZE = '10M';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('documents', FileType::class, [
            'label' => false,
            'multiple' => true,
            'mapped' => false,
            'required' => true,
            'attr' => [
                'accept' => implode(',', DocumentUploadService::ALLOWED_MIME_TYPES),
                'multiple' => 'multiple',
            ],
            'constraints' => [
                new Count(
                    min: 1,
                    max: self::MAX_FILES,
                    minMessage: 'wizard.step0.error.no_files',
                    maxMessage: 'wizard.step0.error.too_many_files',
                ),
                new All([
                    new File(
                        maxSize: self::MAX_FILE_SIZE,
                        mimeTypes: DocumentUploadService::ALLOWED_MIME_TYPES,
                        mimeTypesMessage: 'wizard.step0.error.invalid_mime',
                        maxSizeMessage: 'wizard.step0.error.file_too_large',
                    ),
                ]),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // No data class — files don't bind to an entity at this stage.
            'data_class' => null,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'wizard_step0_documents',
        ]);
    }
}
