<?php declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Caretaker;
use App\Service\CaretakerService;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\CaretakerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * /api/v1 caretaker writes: validated before flushing (incl. the embedded
 * address), rejected PATCHes are reverted, and detaching removes orphans only.
 */
final class CaretakerServiceTest extends KernelTestCase
{
    public function testCreateAttachesValidCaretakerWithAddress(): void
    {
        $bookcase = BookcaseFactory::createOne();
        $caretaker = new Caretaker();
        $caretaker->name = 'Jane';

        $this->assertSame([], $this->service()->create($bookcase, $caretaker, ['city' => ' Berlin ', 'street' => 'Main']));

        $this->assertNotNull($caretaker->id);
        $this->assertTrue($bookcase->caretakers->contains($caretaker));
        $this->assertSame('Berlin', $this->service()->toArray($caretaker)['address']['city']);
    }

    public function testCreateRejectsOverlongFieldsWithoutPersisting(): void
    {
        $caretaker = new Caretaker();
        $caretaker->name = str_repeat('n', 256);

        $violations = $this->service()->create(BookcaseFactory::createOne(), $caretaker, ['zipcode' => str_repeat('1', 129)]);

        $this->assertSame(['name', 'address.zipcode'], array_column($violations, 'field'));
        $this->assertSame(0, $this->em()->getRepository(Caretaker::class)->count([]));
    }

    public function testRejectedUpdateIsReverted(): void
    {
        $caretaker = CaretakerFactory::createOne(['name' => 'Old']);
        $caretaker->name = str_repeat('n', 256);

        $this->assertNotSame([], $this->service()->update($caretaker, null));

        $this->assertSame('Old', $caretaker->name);
    }

    public function testDetachRemovesOrphanButKeepsSharedCaretaker(): void
    {
        $a = BookcaseFactory::createOne();
        $b = BookcaseFactory::createOne();
        $shared = CaretakerFactory::createOne();
        $only = CaretakerFactory::createOne();
        $a->addCaretaker($shared);
        $b->addCaretaker($shared);
        $a->addCaretaker($only);
        $this->em()->flush();
        $this->em()->refresh($shared);
        $this->em()->refresh($only);

        $sharedId = $shared->id;
        $onlyId = $only->id;

        $this->service()->detach($a, $shared);
        $this->service()->detach($a, $only);

        $repo = $this->em()->getRepository(Caretaker::class);
        $this->assertNotNull($repo->find($sharedId));
        $this->assertNull($repo->find($onlyId));
    }

    private function service(): CaretakerService
    {
        return self::getContainer()->get(CaretakerService::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
