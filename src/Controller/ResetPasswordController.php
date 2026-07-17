<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ResetPasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use App\Repository\UserRepository;
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
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private ResetPasswordHelperInterface $resetPasswordHelper,
        private UserRepository $userRepository,
        private TranslatorInterface $translator,
        #[Autowire('%app.mailer_from%')]
        private string $mailerFrom,
        #[Autowire('%app.brand_name%')]
        private string $brandName,
    ) {}

    #[Route('/forgot-password', name: 'app_forgot_password')]
    public function request(Request $request, MailerInterface $mailer, RateLimiterFactory $forgotPasswordLimiter): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $limiter = $forgotPasswordLimiter->create($request->getClientIp());
            if (!$limiter->consume()->isAccepted()) {
                $this->addFlash('warning', $this->translator->trans('rate_limit.forgot_password'));

                return $this->redirectToRoute('app_forgot_password');
            }

            return $this->processSendingPasswordResetEmail((string) $form->get('email')->getData(), $mailer);
        }

        return $this->render('reset_password/request.html.twig', [
            'requestForm' => $form,
        ]);
    }

    private function processSendingPasswordResetEmail(string $emailFormData, MailerInterface $mailer): Response
    {
        $user = $this->userRepository->findOneBy(['email' => $emailFormData]);

        // Always redirect to check-email, even if no user was found, so the response never
        // reveals whether the address is registered.
        if ($user) {
            try {
                $resetToken = $this->resetPasswordHelper->generateResetToken($user);

                $mailer->send(
                    (new TemplatedEmail())
                        ->from(new Address($this->mailerFrom, $this->brandName))
                        ->to($user->getEmail())
                        ->subject($this->translator->trans('reset_password.email.subject'))
                        ->htmlTemplate('reset_password/email.html.twig')
                        ->context(['resetToken' => $resetToken])
                );

                $this->setTokenObjectInSession($resetToken);
            } catch (ResetPasswordExceptionInterface) {
                // Don't reveal whether a user account was found or not.
            }
        }

        return $this->redirectToRoute('app_check_email_reset');
    }

    #[Route('/forgot-password/check-email', name: 'app_check_email_reset')]
    public function checkEmail(): Response
    {
        $resetToken = $this->getTokenObjectFromSession();

        // Generate a fake token if the user does not exist or someone hit this page directly
        $tokenLifetime = $resetToken ? $this->resetPasswordHelper->getTokenLifetime() : 3600;

        return $this->render('reset_password/check_email.html.twig', [
            'tokenLifetime' => $tokenLifetime,
        ]);
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password')]
    public function reset(Request $request, UserPasswordHasherInterface $passwordHasher, ?string $token = null): Response
    {
        if ($token) {
            // Store the token in session and redirect to the same page without the token in URL
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('app_reset_password');
        }

        $token = $this->getTokenFromSession();

        if (null === $token) {
            throw $this->createNotFoundException();
        }

        try {
            /** @var User $user */
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $e) {
            $this->addFlash('danger', $this->translator->trans('reset_password.flash.token_invalid'));

            return $this->redirectToRoute('app_forgot_password');
        }

        $form = $this->createForm(ResetPasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->resetPasswordHelper->removeResetRequest($token);

            $user->setPassword($passwordHasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));
            $this->userRepository->getEntityManager()->flush();

            $this->cleanSessionAfterReset();

            $this->addFlash('success', $this->translator->trans('reset_password.flash.success'));

            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig', [
            'resetForm' => $form,
        ]);
    }
}
