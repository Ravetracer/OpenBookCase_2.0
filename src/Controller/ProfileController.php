<?php

namespace App\Controller;

use App\Config\Locales;
use App\Entity\User;
use App\Enums\NotificationChannel;
use App\Repository\WishlistItemRepository;
use App\Service\LocaleService;
use App\Service\UserAccountService;
use App\Service\UserDeletionService;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/profile', name: 'app_profile_')]
class ProfileController extends AbstractController
{
    public function __construct(
        private readonly UserAccountService $accounts,
        private readonly UserDeletionService $userDeletion,
        private readonly LocaleService $localeService,
        private readonly WishlistItemRepository $wishlistItemRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Profile modal body. Rendered inline via render(controller()) in base.html.twig,
     * so it always reflects the current user without a separate fetch.
     */
    public function modal(#[CurrentUser] ?User $user): Response
    {
        return $this->render('profile/_modal.html.twig', $this->accounts->profileModalView($user));
    }

    /**
     * The current user's wishlist list as an HTML fragment. Fetched by
     * profile_controller on the `wishlist:changed` event so a wish added/changed
     * from a bookcase's wishlist modal shows up in the profile without a reload.
     */
    #[Route('/wishlist', name: 'wishlist', methods: ['GET'])]
    public function wishlist(#[CurrentUser] ?User $user): Response
    {
        if ($user === null) {
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        return $this->render('profile/_wishlist.html.twig', [
            'wishlistItems' => $this->wishlistItemRepository->findForUser($user),
        ]);
    }

    #[Route('/email', name: 'email', methods: ['POST'])]
    public function updateEmail(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->isCsrfTokenValid('profile_email', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => $this->translator->trans('flash.invalid_token')], Response::HTTP_BAD_REQUEST);
        }

        $email = trim((string) $request->request->get('email'));

        return match ($this->accounts->changeOwnEmail($user, $email)) {
            UserAccountService::EMAIL_INVALID => new JsonResponse(['error' => $this->translator->trans('flash.invalid_email')], Response::HTTP_BAD_REQUEST),
            UserAccountService::EMAIL_TAKEN => new JsonResponse(['error' => $this->translator->trans('flash.email_taken')], Response::HTTP_CONFLICT),
            UserAccountService::EMAIL_UNCHANGED => new JsonResponse(['status' => 'success', 'email' => $email], Response::HTTP_OK),
            default => new JsonResponse([
                'status' => 'success',
                'email' => $email,
                'verificationSent' => true,
                'message' => $this->translator->trans('flash.email_changed_verify'),
            ], Response::HTTP_OK),
        };
    }

    #[Route('/notifications', name: 'notifications', methods: ['POST'])]
    public function updateNotifications(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->isCsrfTokenValid('profile_notifications', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => $this->translator->trans('flash.invalid_token')], Response::HTTP_BAD_REQUEST);
        }

        $channel = NotificationChannel::tryFrom((string) $request->request->get('channel'));
        if ($channel === null) {
            return new JsonResponse(['error' => $this->translator->trans('flash.unknown_channel')], Response::HTTP_BAD_REQUEST);
        }

        $this->accounts->setNotificationChannel($user, $channel);

        return new JsonResponse(['status' => 'success', 'channel' => $channel->value], Response::HTTP_OK);
    }

    #[Route('/language', name: 'language', methods: ['POST'])]
    public function updateLanguage(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->isCsrfTokenValid('profile_language', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => $this->translator->trans('flash.invalid_token')], Response::HTTP_BAD_REQUEST);
        }

        $locale = (string) $request->request->get('locale');
        if (!Locales::isSupported($locale)) {
            return new JsonResponse(['error' => $this->translator->trans('flash.unknown_language')], Response::HTTP_BAD_REQUEST);
        }

        // The cookie mirrors the choice so it survives even if the user later logs out.
        $response = new JsonResponse(['status' => 'success', 'locale' => $locale], Response::HTTP_OK);
        $response->headers->setCookie($this->localeService->remember($user, $locale));

        return $response;
    }

    #[Route('/home', name: 'home', methods: ['POST'])]
    public function updateHome(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->isCsrfTokenValid('profile_home', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => $this->translator->trans('flash.invalid_token')], Response::HTTP_BAD_REQUEST);
        }

        if ($request->request->getBoolean('clear')) {
            $this->accounts->clearHome($user);

            return new JsonResponse(['status' => 'success', 'cleared' => true], Response::HTTP_OK);
        }

        if (!$this->accounts->updateHome($user, $request->request)) {
            return new JsonResponse(['error' => $this->translator->trans('flash.invalid_position')], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'status' => 'success',
            'enabled' => $user->useHomeLocation,
            'latitude' => $user->homeLatitude,
            'longitude' => $user->homeLongitude,
            'zoom' => $user->homeZoom,
            'label' => $user->homeLabel,
        ], Response::HTTP_OK);
    }

    #[Route('/delete', name: 'delete', methods: ['POST'])]
    public function delete(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->isCsrfTokenValid('profile_delete', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => $this->translator->trans('flash.invalid_token')], Response::HTTP_BAD_REQUEST);
        }

        $this->userDeletion->deleteOwnAccount($user, $request->getSession());

        return new JsonResponse(['status' => 'success', 'redirect' => $this->generateUrl('app_index')], Response::HTTP_OK);
    }
}
