<?php

namespace App\Service;

use App\Config\Locales;
use App\Entity\ApiApplication;
use App\Entity\Bookcase;
use App\Entity\Message;
use App\Entity\User;
use App\Enums\MessageType;

use Doctrine\ORM\EntityManagerInterface;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Single entry point for emitting system notifications. Upcoming features
 * (watchlist changes, wishlist matches) call notify(); this service decides
 * how to deliver it based on the recipient's NotificationChannel preference.
 */
class MessageService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
    ) {
    }

    /**
     * Deliver a system notification to one user, honouring their channel preference.
     *
     * @return Message|null the stored Message when delivered to the inbox, or null
     *                      for a successful e-mail-only delivery (nothing is stored)
     */
    public function notify(
        User $recipient,
        string $body,
        MessageType $type = MessageType::Update,
        ?string $subject = null,
        ?Bookcase $relatedBookcase = null,
        ?User $sender = null,
        ?ApiApplication $apiApplication = null,
    ): ?Message {
        $channel = $recipient->notificationChannel;
        $emailUsable = $recipient->isVerified && !empty($recipient->email);

        if ($channel->deliversEmail() && $emailUsable) {
            $this->sendEmail($recipient, $body, $subject, $relatedBookcase);
        }

        // Store internally when the user wants it, or as a fallback when an
        // e-mail-only delivery can't reach a usable address (never lose a notice).
        // Conversation messages are ALWAYS stored so the thread persists for both sides.
        if ($apiApplication !== null || $channel->deliversInternal() || !$emailUsable) {
            return $this->store($recipient, $body, $type, $subject, $relatedBookcase, $sender, $apiApplication);
        }

        return null;
    }

    /**
     * Notify several recipients with the same content.
     *
     * @param iterable<User> $recipients
     */
    public function notifyMany(
        iterable $recipients,
        string $body,
        MessageType $type = MessageType::Update,
        ?string $subject = null,
        ?Bookcase $relatedBookcase = null,
        ?User $sender = null,
        ?ApiApplication $apiApplication = null,
    ): void {
        foreach ($recipients as $recipient) {
            $this->notify($recipient, $body, $type, $subject, $relatedBookcase, $sender, $apiApplication);
        }
    }

    private function store(
        User $recipient,
        string $body,
        MessageType $type,
        ?string $subject,
        ?Bookcase $relatedBookcase,
        ?User $sender = null,
        ?ApiApplication $apiApplication = null,
    ): Message {
        $message = new Message();
        $message->recipient = $recipient;
        $message->type = $type;
        $message->subject = $subject;
        $message->body = $body;
        $message->relatedBookcase = $relatedBookcase;
        $message->sender = $sender;
        $message->apiApplication = $apiApplication;

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        return $message;
    }

    private function sendEmail(
        User $recipient,
        string $body,
        ?string $subject,
        ?Bookcase $relatedBookcase,
    ): void {
        $username = $recipient->getUserIdentifier();
        $email = (new TemplatedEmail())
            ->from(new Address('info@openbookcase.de', 'OpenBookCase'))
            ->to(new Address($recipient->email, $username))
            ->subject($subject ?? 'OpenBookCase notification')
            ->htmlTemplate('emails/notification.html.twig')
            ->context([
                'username' => $username,
                'subject' => $subject,
                'body' => $body,
                'bookcase' => $relatedBookcase,
                // The e-mail is rendered during the sender's request, so the template
                // must translate its chrome in the recipient's own language — resolved
                // to the same default the body/subject use, so a recipient with no
                // language set doesn't get German chrome around an English body.
                'locale' => Locales::isSupported($recipient->language) ? $recipient->language : Locales::DEFAULT,
            ]);

        $this->mailer->send($email);
    }
}
