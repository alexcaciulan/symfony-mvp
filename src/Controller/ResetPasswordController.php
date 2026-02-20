<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
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
    ) {}

    #[Route('/forgot-password', name: 'app_forgot_password')]
    public function request(Request $request, \Symfony\Component\Mailer\MailerInterface $mailer): Response
    {
        if ($request->isMethod('POST')) {
            $email = $request->getPayload()->getString('email');

            $user = $this->userRepository->findOneBy(['email' => $email]);

            if ($user) {
                try {
                    $resetToken = $this->resetPasswordHelper->generateResetToken($user);

                    $emailMessage = (new TemplatedEmail())
                        ->from(new Address($this->mailerFrom, $this->translator->trans('registration.sender_name')))
                        ->to($user->getEmail())
                        ->subject($this->translator->trans('reset_password.email.subject'))
                        ->htmlTemplate('reset_password/email.html.twig')
                        ->context([
                            'resetToken' => $resetToken,
                        ]);

                    $mailer->send($emailMessage);
                } catch (ResetPasswordExceptionInterface) {
                    // Don't reveal whether a user account was found or not
                }
            }

            // Always redirect to check-email, even if email not found (security)
            if (isset($resetToken)) {
                $this->setTokenObjectInSession($resetToken);
            }

            return $this->redirectToRoute('app_check_email_reset');
        }

        return $this->render('reset_password/request.html.twig');
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

        if ($request->isMethod('POST')) {
            $newPassword = $request->getPayload()->getString('password');
            $confirmPassword = $request->getPayload()->getString('password_confirm');

            if (mb_strlen($newPassword) < 6) {
                $this->addFlash('danger', $this->translator->trans('reset_password.flash.password_too_short'));

                return $this->render('reset_password/reset.html.twig');
            }

            if ($newPassword !== $confirmPassword) {
                $this->addFlash('danger', $this->translator->trans('reset_password.flash.passwords_mismatch'));

                return $this->render('reset_password/reset.html.twig');
            }

            $this->resetPasswordHelper->removeResetRequest($token);

            $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
            $this->userRepository->getEntityManager()->flush();

            $this->cleanSessionAfterReset();

            $this->addFlash('success', $this->translator->trans('reset_password.flash.success'));

            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig');
    }
}
