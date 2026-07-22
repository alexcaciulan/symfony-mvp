<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\ExtractionMode;
use App\Form\AiProcessingAgreementType;
use App\Form\ChangePasswordType;
use App\Form\ProfileEditType;
use App\Service\AuditLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/profile')]
class ProfileController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private TranslatorInterface $translator,
        private AuditLogService $auditLog,
        private string $appEnv,
    ) {}

    #[Route('', name: 'app_profile', methods: ['GET'])]
    public function show(): Response
    {
        return $this->render('profile/show.html.twig', [
            'user' => $this->getUser(),
        ]);
    }

    #[Route('/edit', name: 'app_profile_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(ProfileEditType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            $this->addFlash('success', $this->translator->trans('profile.flash.edit_success'));

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('profile/edit.html.twig', [
            'form' => $form,
        ]);
    }

    /**
     * Records the lawyer's agreement to sub-processing by Anthropic and, with
     * it, lifts extraction out of LOCAL_ONLY. Without this step no document
     * reaches an AI provider: an AI_ONLY account gets SKIPPED_BY_POLICY, a
     * legacy account falls back to its local tiers.
     */
    #[Route('/ai-agreement', name: 'app_profile_ai_agreement', methods: ['GET', 'POST'])]
    public function aiProcessingAgreement(Request $request): Response
    {
        $this->denyIfDraftInProduction();

        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(AiProcessingAgreementType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Only lift the floor. Overwriting an account that already runs
            // MAX_ACCURACY would silently downgrade its extraction quality as a
            // side effect of signing a legal text.
            if (!$user->getExtractionMode()->isAiAllowed()) {
                $user->setExtractionMode(ExtractionMode::BALANCED);
            }
            $user->setAiProcessingAgreementAt(new \DateTimeImmutable());
            $user->setAiProcessingAgreementVersion(AiProcessingAgreementType::CURRENT_VERSION);
            $this->em->flush();

            $this->auditLog->log(
                action: 'ai_processing_agreement_accepted',
                entityType: 'User',
                entityId: (string) $user->getId(),
                newData: ['version' => AiProcessingAgreementType::CURRENT_VERSION],
            );

            $this->addFlash('success', $this->translator->trans('profile.ai_agreement.flash.accepted'));

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('profile/ai_agreement.html.twig', [
            'form' => $form,
            'user' => $user,
            'agreementVersion' => AiProcessingAgreementType::CURRENT_VERSION,
        ]);
    }

    /**
     * The other half of the choice, and what makes the agreement text truthful
     * about being reversible. Sets local-only extraction and clears the stamp;
     * the audit entry keeps the trace of both the acceptance and the withdrawal.
     */
    #[Route('/ai-agreement/withdraw', name: 'app_profile_ai_agreement_withdraw', methods: ['POST'])]
    public function withdrawAiProcessingAgreement(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('ai_processing_agreement_withdraw', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        /** @var User $user */
        $user = $this->getUser();
        $previousVersion = $user->getAiProcessingAgreementVersion();
        $user->setExtractionMode(ExtractionMode::LOCAL_ONLY);
        $user->setAiProcessingAgreementAt(null);
        $user->setAiProcessingAgreementVersion(null);
        $this->em->flush();

        $this->auditLog->log(
            action: 'ai_processing_agreement_withdrawn',
            entityType: 'User',
            entityId: (string) $user->getId(),
            oldData: ['version' => $previousVersion],
        );

        $this->addFlash('success', $this->translator->trans('profile.ai_agreement.flash.withdrawn'));

        return $this->redirectToRoute('app_profile');
    }

    /**
     * The agreement text is still a draft, and the sub-processing chain it
     * describes (art. 28 contract, art. 46 clauses, provider DPA) is not signed.
     * Signing a draft would produce evidence of an agreement that its own text
     * disclaims, so the screen is unreachable in production until the wording is
     * final.
     */
    private function denyIfDraftInProduction(): void
    {
        if ($this->appEnv === 'prod' && str_contains(AiProcessingAgreementType::CURRENT_VERSION, 'draft')) {
            throw $this->createNotFoundException('AI processing agreement text is still a draft');
        }
    }

    #[Route('/change-password', name: 'app_profile_change_password', methods: ['GET', 'POST'])]
    public function changePassword(Request $request, UserPasswordHasherInterface $passwordHasher, Security $security): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $currentPassword = $form->get('currentPassword')->getData();

            if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
                $this->addFlash('danger', $this->translator->trans('profile.flash.current_password_wrong'));

                return $this->redirectToRoute('app_profile_change_password');
            }

            $newPassword = $form->get('newPassword')->getData();
            $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
            $user->regenerateSecurityStamp();
            $this->em->flush();

            // The new stamp/hash lazily logs out every OTHER session on its next request;
            // re-login the current one so the acting device carries the fresh stamp.
            $security->login($user, 'form_login', 'main');

            $this->addFlash('success', $this->translator->trans('profile.flash.password_changed'));
            $this->addFlash('info', $this->translator->trans('logged_out_other_devices', [], 'security'));

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('profile/change_password.html.twig', [
            'form' => $form,
        ]);
    }
}
