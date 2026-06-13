<?php declare(strict_types=1);

namespace App\Controller;

use App\Config\Locales;
use App\Entity\User;
use App\EventSubscriber\LocaleSubscriber;

use Doctrine\ORM\EntityManagerInterface;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Switches the UI language. For logged-in users the choice is saved on the
 * profile (User.language); for everyone the `obc_locale` cookie is set so the
 * server can render translated HTML on the next request (anonymous persistence,
 * mirrored to localStorage client-side). Linked from the navbar selector.
 */
class LocaleController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/language/{locale}', name: 'app_language_switch', methods: ['GET'])]
    public function switch(string $locale, Request $request): RedirectResponse
    {
        if (!Locales::isSupported($locale)) {
            throw $this->createNotFoundException();
        }

        $user = $this->getUser();
        if ($user instanceof User) {
            $user->language = $locale;
            $this->entityManager->flush();
        }

        $response = new RedirectResponse($this->safeRedirectTarget($request));
        $response->headers->setCookie(
            Cookie::create(LocaleSubscriber::COOKIE, $locale, strtotime('+1 year'), '/', null, false, false),
        );

        return $response;
    }

    /**
     * Redirect back to the page the user came from, but only if it's on this
     * host (avoid an open redirect); otherwise the homepage.
     */
    private function safeRedirectTarget(Request $request): string
    {
        $referer = $request->headers->get('referer');
        if ($referer !== null && str_starts_with($referer, $request->getSchemeAndHttpHost())) {
            return $referer;
        }

        return $this->generateUrl('app_index');
    }
}
