<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Answers a deadline action that was fired from the global agenda instead of from
 * inside a case.
 *
 * The routes are the same ones the case page posts to, so authorization through
 * CaseVoter and the audit trail stay in a single place. What differs is the answer:
 * the case page is updated by a stream aimed at markup that does not exist on the
 * agenda, and rendering it there would leave the row untouched and, on the redirect
 * fallback, throw the lawyer out of the triage screen and into the case.
 *
 * The acting form says where it is by posting {@see self::CONTEXT_FIELD}. Without
 * that field nothing here runs and the controllers answer exactly as they always
 * have.
 */
final class AgendaResponseFactory
{
    /** Hidden field an agenda form posts so the action answers with agenda markup. */
    public const CONTEXT_FIELD = '_context';

    public const CONTEXT_AGENDA = 'agenda';

    private const TURBO_STREAM_MIME = 'text/vnd.turbo-stream.html';

    public function __construct(
        private readonly Environment $twig,
        private readonly DeadlinePageViewBuilder $pageViewBuilder,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public function isAgendaRequest(Request $request): bool
    {
        return $request->getPayload()->getString(self::CONTEXT_FIELD) === self::CONTEXT_AGENDA;
    }

    public function wantsTurboStream(Request $request): bool
    {
        return str_contains((string) $request->headers->get('Accept', ''), self::TURBO_STREAM_MIME);
    }

    /**
     * The three regions of the agenda an action can change, rebuilt from scratch plus
     * a toast.
     *
     * Rebuilding beats swapping the single row that was acted on: closing a term also
     * moves the counters, the count and the "oldest arrear" hint in the section
     * header, whether the section renders at all, the rail and the blockage zone. A
     * row swap would leave every one of those stating a number the lawyer can no
     * longer see, and a stale count on a triage screen is worse than a larger answer.
     *
     * `$closeModalId` dismisses the dialog the action was fired from, and is passed
     * only on success: a rejected submission keeps its dialog open with what the
     * lawyer typed still in it.
     *
     * `$extraToastKey` is a second, always-warning message some acts carry next to
     * their own outcome, such as the invoice raised when a case is activated beyond
     * the plan. It is billing the lawyer is told about on the case page, so it must
     * not go missing because the same act was fired from here.
     */
    public function stream(
        Request $request,
        User $user,
        string $toastVariant,
        string $toastKey,
        ?string $closeModalId = null,
        ?string $extraToastKey = null,
    ): Response {
        $view = $this->pageViewBuilder->build($user, DeadlineAgendaFilter::fromRequest($request));

        return new Response(
            $this->twig->render('deadlines/_agenda_turbo_stream.html.twig', [
                'view' => $view,
                'toast_variant' => $toastVariant,
                'toast_key' => $toastKey,
                'close_modal_id' => $closeModalId,
                'extra_toast_key' => $extraToastKey,
            ]),
            Response::HTTP_OK,
            ['Content-Type' => self::TURBO_STREAM_MIME . '; charset=utf-8'],
        );
    }

    /** Fallback for a client without Turbo: back to the agenda, filter included. */
    public function redirect(Request $request): RedirectResponse
    {
        return new RedirectResponse($this->urlGenerator->generate(
            'app_deadlines',
            DeadlineAgendaFilter::fromRequest($request)->queryParameters(),
        ));
    }
}
