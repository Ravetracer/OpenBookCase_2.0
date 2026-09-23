<?php declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Vich\UploaderBundle\Event\Event;
use Vich\UploaderBundle\Event\Events;

/**
 * Defence in depth: never let VichUploader delete a file whose stored name
 * contains a path (e.g. a tampered `../../var/x`), which would escape the
 * upload directory. Server-generated names are always plain basenames.
 */
#[AsEventListener(event: Events::PRE_REMOVE)]
final class UploadPathGuardListener
{
    public function __invoke(Event $event): void
    {
        $name = (string) $event->getMapping()->getFileName($event->getObject());
        if ($name !== basename($name)) {
            $event->cancel();
        }
    }
}
