<?php

namespace App\Controller;

use App\Entity\Image;
use App\Entity\Message;
use App\Entity\Rating;
use App\Entity\User;
use App\Entity\WatchlistItem;
use App\Config\Locales;
use App\Entity\WishlistItem;
use App\Enums\NotificationChannel;
use App\EventSubscriber\LocaleSubscriber;
use App\Repository\ApiApplicationRepository;
use App\Repository\MessageRepository;
use App\Repository\WishlistItemRepository;

use Doctrine\ORM\EntityManagerInterface;

use Symfony\Component\HttpFoundation\Cookie;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/profile', name: 'app_profile_')]
class ProfileController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly WishlistItemRepository $wishlistItemRepository,
        private readonly TranslatorInterface $translator,
        private readonly ApiApplicationRepository $apiApplications,
        private readonly MessageRepository $messages,
    ) {
    }

    /**
     * Profile modal body. Rendered inline via render(controller()) in base.html.twig,
     * so it always reflects the current user without a separate fetch.
     */
    public function modal(): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();

        $apiApplication = $user instanceof User ? $this->apiApplications->findLatestForUser($user) : null;

        return $this->render('profile/_modal.html.twig', [
            'user' => $user,
            'wishlistItems' => $user instanceof User ? $this->wishlistItemRepository->findForUser($user) : [],
            'apiApplication' => $apiApplication,
            'apiThread' => $apiApplication !== null ? $this->messages->findThreadForApplication($apiApplication) : [],
            'apiScopes' => \App\Entity\ApiApplication::AVAILABLE_SCOPES,
        ]);
    }

    /**
     * The current user's wishlist list as an HTML fragment. Fetched by
     * profile_controller on the `wishlist:changed` event so a wish added/changed
     * from a bookcase's wishlist modal shows up in the profile without a reload.
     */
    #[Route('/wishlist', name: 'wishlist', methods: ['GET'])]
    public function wishlist(): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        return $this->render('profile/_wishlist.html.twig', [
            'wishlistItems' => $this->wishlistItemRepository->findForUser($user),
        ]);
    }

    #[Route('/email', name: 'email', methods: ['POST'])]
    public function updateEmail(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if ($user === null) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->isCsrfTokenValid('profile_email', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => $this->translator->trans('flash.invalid_token')], Response::HTTP_BAD_REQUEST);
        }

        $email = trim((string) $request->request->get('email'));
        $violations = $this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()]);
        if (count($violations) > 0) {
            return new JsonResponse(['error' => $this->translator->trans('flash.invalid_email')], Response::HTTP_BAD_REQUEST);
        }

        $user->email = $email;
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'success', 'email' => $email], Response::HTTP_OK);
    }

    #[Route('/notifications', name: 'notifications', methods: ['POST'])]
    public function updateNotifications(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
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

        $user->notificationChannel = $channel;
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'success', 'channel' => $channel->value], Response::HTTP_OK);
    }

    #[Route('/language', name: 'language', methods: ['POST'])]
    public function updateLanguage(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
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

        $user->language = $locale;
        $this->entityManager->flush();

        // Mirror the choice into the cookie so it survives even if the user later logs out.
        $response = new JsonResponse(['status' => 'success', 'locale' => $locale], Response::HTTP_OK);
        $response->headers->setCookie(
            Cookie::create(LocaleSubscriber::COOKIE, $locale, strtotime('+1 year'), '/', null, false, false),
        );

        return $response;
    }

    #[Route('/home', name: 'home', methods: ['POST'])]
    public function updateHome(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if ($user === null) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->isCsrfTokenValid('profile_home', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => $this->translator->trans('flash.invalid_token')], Response::HTTP_BAD_REQUEST);
        }

        // Remove the home position entirely: clear the coordinates and disable it.
        if ($request->request->getBoolean('clear')) {
            $user->homeLatitude = null;
            $user->homeLongitude = null;
            $user->homeZoom = null;
            $user->homeLabel = null;
            $user->useHomeLocation = false;
            $this->entityManager->flush();

            return new JsonResponse(['status' => 'success', 'cleared' => true], Response::HTTP_OK);
        }

        // Optional user-chosen name (e.g. "Home", "Office"); empty → no label.
        $label = trim((string) $request->request->get('label'));
        $user->homeLabel = $label !== '' ? mb_substr($label, 0, 50) : null;

        $enabled = $request->request->getBoolean('enabled');

        // Coordinates are required whenever the feature is enabled; otherwise the
        // map would have nowhere to centre. Disabling keeps the stored values.
        if ($enabled || $request->request->has('latitude')) {
            $lat = (float) $request->request->get('latitude');
            $lon = (float) $request->request->get('longitude');
            $zoom = (int) $request->request->get('zoom');

            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                return new JsonResponse(['error' => $this->translator->trans('flash.invalid_position')], Response::HTTP_BAD_REQUEST);
            }

            $user->homeLatitude = $lat;
            $user->homeLongitude = $lon;
            $user->homeZoom = max(1, min(19, $zoom ?: 13));
        }

        $user->useHomeLocation = $enabled;
        $this->entityManager->flush();

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
    public function delete(Request $request): JsonResponse
    {
        /** @var User|null $current */
        $current = $this->getUser();
        if ($current === null) {
            return new JsonResponse(['error' => $this->translator->trans('flash.auth_required')], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->isCsrfTokenValid('profile_delete', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => $this->translator->trans('flash.invalid_token')], Response::HTTP_BAD_REQUEST);
        }

        $userId = $current->id;

        // Bulk operations keyed by id (not the entity) so they run straight against
        // the DB regardless of what the UnitOfWork currently has managed.
        // Keep the uploaded images but sever the personal link.
        $this->entityManager->createQuery(
            'UPDATE ' . Image::class . ' i SET i.uploadedBy = NULL WHERE i.uploadedBy = :id'
        )->setParameter('id', $userId, 'ulid')->execute();

        // Delete all personal data tied to the account.
        $this->entityManager->createQuery(
            'DELETE ' . Rating::class . ' r WHERE r.user = :id'
        )->setParameter('id', $userId, 'ulid')->execute();

        $this->entityManager->createQuery(
            'DELETE ' . WishlistItem::class . ' w WHERE w.user = :id'
        )->setParameter('id', $userId, 'ulid')->execute();

        // Keep wishes this user dropped a copy for, but sever the personal link
        // (the wish stays valid; it just no longer points at a deleted account).
        $this->entityManager->createQuery(
            'UPDATE ' . WishlistItem::class . ' w SET w.droppedBy = NULL WHERE w.droppedBy = :id'
        )->setParameter('id', $userId, 'ulid')->execute();

        $this->entityManager->createQuery(
            'DELETE ' . Message::class . ' m WHERE m.recipient = :id'
        )->setParameter('id', $userId, 'ulid')->execute();

        $this->entityManager->createQuery(
            'DELETE ' . WatchlistItem::class . ' w WHERE w.user = :id'
        )->setParameter('id', $userId, 'ulid')->execute();

        // Detach any stale managed entities (e.g. images with the old owner snapshot)
        // so the flush below can't re-sync the link we just removed, then delete the user.
        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if ($user !== null) {
            $this->entityManager->remove($user);
            $this->entityManager->flush();
        }

        // Log the (now deleted) user out.
        $this->tokenStorage->setToken(null);
        $session = $request->getSession();
        $session->invalidate();

        return new JsonResponse(['status' => 'success', 'redirect' => $this->generateUrl('app_index')], Response::HTTP_OK);
    }
}
