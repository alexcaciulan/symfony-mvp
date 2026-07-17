<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use App\Service\Billing\SubscriptionService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

class RegistrationController extends AbstractController
{
    private const SESSION_KEY_PENDING_EMAIL = 'registration_pending_email';

    public function __construct(
        private EmailVerifier $emailVerifier,
        #[Autowire('%app.mailer_from%')]
        private string $mailerFrom,
        private TranslatorInterface $translator,
        private SubscriptionService $subscriptionService,
        private LoggerInterface $logger,
        private MailerInterface $mailer,
        private UserRepository $userRepository,
        #[Autowire('%app.brand_name%')]
        private string $brandName,
    ) {
    }

    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, RateLimiterFactory $registrationLimiter): Response
    {
        if ($request->isMethod('POST')) {
            $limiter = $registrationLimiter->create($request->getClientIp());
            if (!$limiter->consume()->isAccepted()) {
                $this->addFlash('warning', $this->translator->trans('rate_limit.registration'));

                return $this->redirectToRoute('app_register');
            }
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        // Validate FIRST: an invalid form renders the same 422 regardless of whether the
        // email exists (no UniqueEntity), so the divergence below only happens for an
        // otherwise-valid submission. This keeps the status code from leaking existence.
        if ($form->isSubmitted() && $form->isValid()) {
            $email = (string) $user->getEmail();
            /** @var string $plainPassword */
            $plainPassword = (string) $form->get('plainPassword')->getData();

            if ($this->userRepository->findOneBy(['email' => $email]) !== null) {
                return $this->handleExistingRegistration($request, $email, $userPasswordHasher, $plainPassword);
            }

            $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));

            try {
                $entityManager->persist($user);
                $entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                // Race: another request registered the same email between the check and the
                // flush. Fall back to the existing-email outcome so it still cannot be told
                // apart from a fresh registration.
                return $this->handleExistingRegistration($request, $email, $userPasswordHasher, $plainPassword);
            }

            $this->sendVerificationEmail($user);

            // No auto-login (keeps this path anonymous like the existing-email one); the
            // trial_started audit is attributed to the system actor. Trial failures are
            // swallowed so a billing misconfig cannot break registration.
            try {
                $this->subscriptionService->startTrial($user);
            } catch (\Throwable $e) {
                $this->logger->error('Trial start failed at registration: ' . $e->getMessage());
            }

            $request->getSession()->set(self::SESSION_KEY_PENDING_EMAIL, $email);

            return $this->redirectToRoute('app_check_email');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    /**
     * Existing-email branch of registration. Runs a throwaway hash so latency matches the
     * new-user path, notifies the real owner, and lands on the same check-email page. No
     * duplicate is created and no session is authenticated.
     */
    private function handleExistingRegistration(Request $request, string $email, UserPasswordHasherInterface $hasher, string $plainPassword): Response
    {
        // Hash against a throwaway User (no DB, so it survives a race-closed EntityManager)
        // to match the new-user branch latency.
        $hasher->hashPassword(new User(), $plainPassword);
        $this->sendDuplicateRegistrationNotice($email);
        $request->getSession()->set(self::SESSION_KEY_PENDING_EMAIL, $email);

        return $this->redirectToRoute('app_check_email');
    }

    #[Route('/register/check-email', name: 'app_check_email')]
    public function checkEmail(Request $request): Response
    {
        // Both registration paths (new and existing-email) are anonymous and flag the
        // pending address in the session, so the page renders identically. The getUser
        // fallback only covers an already-authenticated visitor, not the enum comparison.
        $pendingEmail = $request->getSession()->get(self::SESSION_KEY_PENDING_EMAIL) ?? $this->getUser()?->getUserIdentifier();
        if (!\is_string($pendingEmail)) {
            return $this->redirectToRoute('app_register');
        }

        return $this->render('registration/check_email.html.twig', ['pending_email' => $pendingEmail]);
    }

    #[Route('/register/resend-verification', name: 'app_resend_verification')]
    public function resendVerification(Request $request): Response
    {
        $pendingEmail = $request->getSession()->get(self::SESSION_KEY_PENDING_EMAIL);
        $user = \is_string($pendingEmail) ? $this->userRepository->findOneBy(['email' => $pendingEmail]) : null;
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_register');
        }

        $lastSent = $request->getSession()->get('last_verification_email', 0);
        if (time() - $lastSent < 60) {
            $this->addFlash('warning', $this->translator->trans('flash.verification_throttle'));

            return $this->redirectToRoute('app_check_email');
        }

        // Only actually (re)send for an unverified account, but always show the same
        // generic outcome so an existing (already-verified) address stays indistinguishable.
        if (!$user->isVerified()) {
            $this->sendVerificationEmail($user);
            $request->getSession()->set('last_verification_email', time());
        }

        $this->addFlash('success', $this->translator->trans('flash.verification_resent_generic'));

        return $this->redirectToRoute('app_check_email');
    }

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(Request $request): Response
    {
        // The signed URL carries the user id (see EmailVerifier::sendEmailConfirmation), so
        // verification works without a prior login: the signature is validated against that
        // user. This keeps registration free of the auto-login that would leak enumeration.
        $id = $request->query->get('id');
        $user = $id !== null ? $this->userRepository->find((int) $id) : null;
        if (!$user instanceof User) {
            $this->addFlash('verify_email_error', $this->translator->trans('flash.email_verify_invalid'));

            return $this->redirectToRoute('app_register');
        }

        try {
            $this->emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('verify_email_error', $this->translator->trans($exception->getReason(), [], 'VerifyEmailBundle'));

            return $this->redirectToRoute('app_register');
        }

        $request->getSession()->remove(self::SESSION_KEY_PENDING_EMAIL);
        $this->addFlash('success', $this->translator->trans('flash.email_verified_success'));

        return $this->redirectToRoute('app_login');
    }

    /**
     * Notifies the real owner that someone tried to register with their address, instead
     * of leaking that the account exists. Sent via the async transport like all mail.
     */
    private function sendDuplicateRegistrationNotice(string $recipient): void
    {
        // Sender name is the brand so this security notice reads consistently with the
        // brand-named account wording in its body.
        $email = (new TemplatedEmail())
            ->from(new Address($this->mailerFrom, $this->brandName))
            ->to($recipient)
            ->subject($this->translator->trans('registration.duplicate_attempt.subject'))
            ->htmlTemplate('registration/duplicate_attempt_email.html.twig')
            ->context([
                'login_url' => $this->generateUrl('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);

        $this->mailer->send($email);
    }

    private function sendVerificationEmail(User $user): void
    {
        $this->emailVerifier->sendEmailConfirmation(
            'app_verify_email',
            $user,
            (new TemplatedEmail())
                ->from(new Address($this->mailerFrom, $this->brandName))
                ->to((string) $user->getEmail())
                ->subject($this->translator->trans('registration.email.subject'))
                ->htmlTemplate('registration/confirmation_email.html.twig')
        );
    }
}
