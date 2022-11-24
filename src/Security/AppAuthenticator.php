<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class AppAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $username = $request->request->get('username', '');

        $request->getSession()->set(Security::LAST_USERNAME, $username);

        return new Passport(
            new UserBadge($username, function ($userIdentifier) {
                /** @var User $user */
                $user = $this->userRepository->findOneBy(['username' => $userIdentifier]);

                if (!$user) {
                    throw new UserNotFoundException();
                }

                return $user;
            }),
            new CustomCredentials(
                function ($credentials, User $user) {
                    if ($user->isLegacyUser() && !$user->isLegacyMigrated()) {
                        $legacyPwHash = md5(substr(hash("sha256", $user->getEmail()), 5, 15) . hash("sha512", $credentials));

                        return $user->getLegacyPassword() === $legacyPwHash;
                    }

                    return $this->passwordHasher->isPasswordValid($user, $credentials);
                }, $request->request->get('password', '')
            ),
            [
                new CsrfTokenBadge('authenticate', $request->request->get('_csrf_token')),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        /** @var User $user */
        $user = $token->getUser();
        if ($user->isLegacyUser() && !$user->isLegacyMigrated()) {
            $user->setLegacyMigrated(true)
                ->setLegacyUser(false)
                ->setLegacyPassword('')
                ->setPassword($this->passwordHasher->hashPassword($user, $request->request->get('password', '')))
                ->setIsVerified(true)
                ->setLegacyId(0);

            $this->entityManager->flush();
        }

        if ($targetPath = $this->getTargetPath($request->getSession(), $firewallName)) {
            return new RedirectResponse($targetPath);
        }

        // For example:
        // return new RedirectResponse($this->urlGenerator->generate('some_route'));
        throw new \Exception('TODO: provide a valid redirect inside '.__FILE__);
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}
