<?php declare(strict_types=1);

namespace App\Controller;

use App\Config\Locales;
use App\Entity\User;
use App\Service\LocaleService;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Switches the UI language. For logged-in users the choice is saved on the
 * profile (User.language); for everyone the `obc_locale` cookie is set so the
 * server can render translated HTML on the next request (anonymous persistence,
 * mirrored to localStorage client-side). Linked from the navbar selector.
 */
class LocaleController extends AbstractController
{
    public function __construct(
        private readonly LocaleService $localeService,
    ) {
    }

    #[Route('/language/{locale}', name: 'app_language_switch', methods: ['GET'])]
    public function switch(string $locale, Request $request, #[CurrentUser] ?User $user): RedirectResponse
    {
        if (!Locales::isSupported($locale)) {
            throw $this->createNotFoundException();
        }

        $response = new RedirectResponse($this->localeService->safeRedirectTarget($request));
        $response->headers->setCookie($this->localeService->remember($user, $locale));

        return $response;
    }
}
