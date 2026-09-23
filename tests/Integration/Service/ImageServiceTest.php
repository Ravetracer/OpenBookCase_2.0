<?php declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Image;
use App\Service\ImageService;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\ImageFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The shared upload flow (text + file validation and re-encode before anything
 * is persisted), alt-text updates, deletion and the /api/v1 representation.
 */
final class ImageServiceTest extends KernelTestCase
{
    /** @var list<string> files to remove in tearDown */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function testIsFullAtMaxImages(): void
    {
        $bookcase = BookcaseFactory::createOne();
        ImageFactory::createMany(ImageService::MAX_IMAGES - 1, ['bookcase' => $bookcase]);
        $this->em()->refresh($bookcase);
        $this->assertFalse($this->service()->isFull($bookcase));

        ImageFactory::createOne(['bookcase' => $bookcase]);
        $this->em()->refresh($bookcase);
        $this->assertTrue($this->service()->isFull($bookcase));
    }

    public function testUploadStoresReEncodedImage(): void
    {
        $user = UserFactory::createOne();

        $image = $this->service()->upload(BookcaseFactory::createOne(), $user, $this->jpeg(), 'Me', '');

        $this->assertInstanceOf(Image::class, $image);
        $this->cleanup[] = $this->uploadDir() . $image->filename;
        $this->assertFileExists($this->uploadDir() . $image->filename);
        $this->assertNull($image->altText, 'blank alt text is stored as null');
        $this->assertSame($user, $image->uploadedBy);
    }

    public function testUploadRejectsOverlongTextBeforeTouchingTheFile(): void
    {
        $result = $this->service()->upload(BookcaseFactory::createOne(), UserFactory::createOne(), $this->jpeg(), 'Me', str_repeat('a', ImageService::MAX_TEXT_LENGTH + 1));

        $this->assertSame('flash.text_too_long', $result);
        $this->assertSame(0, $this->em()->getRepository(Image::class)->count([]));
    }

    public function testUploadRejectsNonImage(): void
    {
        $path = $this->tempFile('.jpg');
        file_put_contents($path, '<?php echo "pwned";');
        $file = new UploadedFile($path, 'evil.jpg', 'image/jpeg', null, true);

        $this->assertSame('flash.invalid_image_type', $this->service()->upload(BookcaseFactory::createOne(), UserFactory::createOne(), $file, 'Me', ''));
        $this->assertSame('flash.no_file', $this->service()->upload(BookcaseFactory::createOne(), UserFactory::createOne(), null, 'Me', ''));
        $this->assertSame(0, $this->em()->getRepository(Image::class)->count([]));
    }

    public function testUpdateAltText(): void
    {
        $image = ImageFactory::createOne(['altText' => 'old']);

        $this->assertSame('flash.text_too_long', $this->service()->updateAltText($image, str_repeat('a', ImageService::MAX_TEXT_LENGTH + 1)));
        $this->assertSame('old', $image->altText);

        $this->assertNull($this->service()->updateAltText($image, ''));
        $this->assertNull($image->altText);
    }

    public function testDeleteRemovesRow(): void
    {
        $image = ImageFactory::createOne();

        $this->service()->delete($image);

        $this->assertSame(0, $this->em()->getRepository(Image::class)->count([]));
    }

    public function testToApiArray(): void
    {
        $image = ImageFactory::createOne(['filename' => 'a.jpg', 'filenameThumbnail' => null, 'altText' => 'Shelf']);

        $data = $this->service()->toApiArray($image, 'https://x.test');

        $this->assertSame('https://x.test/images/a.jpg', $data['url']);
        $this->assertNull($data['thumbnailUrl']);
        $this->assertSame('Shelf', $data['altText']);
    }

    private function service(): ImageService
    {
        return self::getContainer()->get(ImageService::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function uploadDir(): string
    {
        return self::getContainer()->getParameter('kernel.project_dir') . '/public/images/';
    }

    private function jpeg(): UploadedFile
    {
        $path = $this->tempFile('.jpg');
        imagejpeg(imagecreatetruecolor(10, 10), $path);

        return new UploadedFile($path, 'test.jpg', 'image/jpeg', null, true);
    }

    private function tempFile(string $suffix): string
    {
        $base = (string) tempnam(sys_get_temp_dir(), 'obc_test_');
        array_push($this->cleanup, $base, $base . $suffix);

        return $base . $suffix;
    }
}
