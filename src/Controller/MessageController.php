<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\MessageRepository;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/messages', name: 'app_message_')]
class MessageController extends AbstractController
{
    public function __construct(
        private readonly MessageRepository $messages,
    ) {
    }

    /**
     * Navbar bell + unread badge. No route — rendered inline via
     * render(controller(...)) in navigation.html.twig so the count reflects the
     * current user on every page load (the legacy, push-free approach).
     */
    public function navIcon(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new Response('');
        }

        return $this->render('messages/_nav_icon.html.twig', [
            'unread' => $this->messages->countUnread($user),
        ]);
    }

    /**
     * Inbox fragment, fetched into the messageModal dialog. Marks everything read
     * as a side effect of opening.
     */
    #[Route('', name: 'inbox', methods: ['GET'])]
    public function inbox(): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if ($user === null) {
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        $messages = $this->messages->findInboxFor($user);
        $this->messages->markAllReadFor($user);

        return $this->render('messages/_inbox.html.twig', [
            'messages' => $messages,
        ]);
    }
}
