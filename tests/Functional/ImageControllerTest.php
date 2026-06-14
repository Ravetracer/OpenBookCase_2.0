<?php declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Image;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\ImageFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class ImageControllerTest extends FunctionalTestCase
{
    /** @var string[] absolute paths created during a test, cleaned up in tearDown */
    private array $cleanup = [];

    private string $uploadDir;

    protected function setUp(): void
    {
        parent::setUp();
        if (!\function_exists('imagejpeg')) {
            $this->markTestSkipped('GD extension (imagejpeg) is required for image upload tests.');
        }
        $this->uploadDir = static::getContainer()->getParameter('kernel.project_dir') . '/public/images/';
    }

    protected function tearDown(): void
    {
        // Remove any files written to the real upload dir during the test.
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        // Also sweep the upload dir for any test_*.jpg-derived files VichUploader named.
        parent::tearDown();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** Build a real, small JPEG on disk and wrap it as an UploadedFile (test mode). */
    private function makeJpeg(): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'obc_test_') . '.jpg';
        $im = imagecreatetruecolor(10, 10);
        imagejpeg($im, $tmp);
        imagedestroy($im);
        $this->cleanup[] = $tmp;

        return new UploadedFile($tmp, 'test.jpg', 'image/jpeg', null, true);
    }

    /** Track the persisted upload so tearDown can remove it from public/images. */
    private function trackUploaded(string $filename): void
    {
        $this->cleanup[] = $this->uploadDir . $filename;
    }

    // ---------------------------------------------------------------------
    // POST /api/bookcase/{id}/image  (upload)
    // ---------------------------------------------------------------------

    public function testUploadRequiresAuth(): void
    {
        $bc = BookcaseFactory::createOne();
        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/image');
        $status = $this->client->getResponse()->getStatusCode();
        $this->assertTrue($status < 200 || $status >= 300, "expected access-denied, got $status");
    }

    public function testUploadNoFileFails(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/image', ['author' => 'Me']);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testUploadAuthorRequired(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $this->client->request(
            'POST',
            '/api/bookcase/' . $bc->id . '/image',
            ['author' => ''],
            ['imageFile' => $this->makeJpeg()],
        );
        $this->assertResponseStatusCodeSame(422);
    }

    public function testUploadSuccessCreatesImageRow(): void
    {
        $user = $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $id = (string) $bc->id;

        $this->client->request(
            'POST',
            '/api/bookcase/' . $id . '/image',
            ['author' => 'Jane Doe', 'altText' => 'a tidy little shelf'],
            ['imageFile' => $this->makeJpeg()],
        );
        $this->assertResponseStatusCodeSame(201);

        $data = $this->json();
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('filename', $data);
        $this->assertSame('Jane Doe', $data['author']);
        $this->assertSame('a tidy little shelf', $data['altText']);
        $this->trackUploaded($data['filename']);

        $this->em()->clear();
        $image = $this->em()->getRepository(Image::class)->find($data['id']);
        $this->assertNotNull($image);
        $this->assertSame('Jane Doe', $image->author);
        $this->assertSame('a tidy little shelf', $image->altText);
        $this->assertSame((string) $user->id, (string) $image->uploadedBy->id);
        // The file was actually written to disk.
        $this->assertFileExists($this->uploadDir . $image->filename);
    }

    public function testUploadEnforcesMaxFiveImages(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        // Pre-seed five image rows (DB rows only, no real files needed for the cap).
        ImageFactory::createMany(5, ['bookcase' => $bc]);

        $this->em()->clear();

        $this->client->request(
            'POST',
            '/api/bookcase/' . $bc->id . '/image',
            ['author' => 'Sixth'],
            ['imageFile' => $this->makeJpeg()],
        );
        $this->assertResponseStatusCodeSame(422);
    }

    // ---------------------------------------------------------------------
    // POST /api/bookcase/{id}/image/{image}/alt
    // ---------------------------------------------------------------------

    public function testUpdateAltStoresText(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $image = ImageFactory::createOne(['bookcase' => $bc, 'altText' => null]);
        $imageId = (string) $image->id;

        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/image/' . $imageId . '/alt', [
            'altText' => 'screen reader description',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->json()['success']);
        $this->assertSame('screen reader description', $this->json()['altText']);

        $this->em()->clear();
        $this->assertSame('screen reader description', $this->em()->getRepository(Image::class)->find($imageId)->altText);
    }

    public function testUpdateAltEmptyStringNullsIt(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $image = ImageFactory::createOne(['bookcase' => $bc, 'altText' => 'before']);
        $imageId = (string) $image->id;

        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/image/' . $imageId . '/alt', ['altText' => '  ']);
        $this->assertResponseIsSuccessful();
        $this->assertNull($this->json()['altText']);

        $this->em()->clear();
        $this->assertNull($this->em()->getRepository(Image::class)->find($imageId)->altText);
    }

    public function testUpdateAltWrongBookcaseIs404(): void
    {
        $this->loginAsUser();
        $other = BookcaseFactory::createOne();
        $image = ImageFactory::createOne(); // belongs to its own bookcase, not $other
        $this->client->request('POST', '/api/bookcase/' . $other->id . '/image/' . $image->id . '/alt', ['altText' => 'x']);
        $this->assertResponseStatusCodeSame(404);
    }

    // ---------------------------------------------------------------------
    // POST /api/bookcase/{id}/image/{image}/rotate
    // ---------------------------------------------------------------------

    public function testRotateRequiresAuth(): void
    {
        $bc = BookcaseFactory::createOne();
        $image = ImageFactory::createOne(['bookcase' => $bc]);
        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/image/' . $image->id . '/rotate', ['direction' => 'cw']);
        $status = $this->client->getResponse()->getStatusCode();
        $this->assertTrue($status < 200 || $status >= 300);
    }

    public function testRotateInvalidDirectionFails(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $image = ImageFactory::createOne(['bookcase' => $bc]);
        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/image/' . $image->id . '/rotate', ['direction' => 'sideways']);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testRotateClockwiseSucceeds(): void
    {
        $user = $this->loginAsUser();
        $bc = BookcaseFactory::createOne();

        // First upload a real image so there's a file on disk to rotate.
        $this->client->request(
            'POST',
            '/api/bookcase/' . $bc->id . '/image',
            ['author' => 'Rotator'],
            ['imageFile' => $this->makeJpeg()],
        );
        $this->assertResponseStatusCodeSame(201);
        $created = $this->json();
        $this->trackUploaded($created['filename']);
        $imageId = $created['id'];

        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/image/' . $imageId . '/rotate', ['direction' => 'cw']);
        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->json()['success']);

        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/image/' . $imageId . '/rotate', ['direction' => 'ccw']);
        $this->assertResponseIsSuccessful();
    }

    // ---------------------------------------------------------------------
    // DELETE /api/bookcase/{id}/image/{image}
    // ---------------------------------------------------------------------

    public function testDeleteRemovesImageRow(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $image = ImageFactory::createOne(['bookcase' => $bc]);
        $imageId = (string) $image->id;

        $this->client->request('DELETE', '/api/bookcase/' . $bc->id . '/image/' . $imageId);
        $this->assertResponseIsSuccessful();
        $this->assertTrue($this->json()['success']);

        $this->em()->clear();
        $this->assertNull($this->em()->getRepository(Image::class)->find($imageId));
    }

    public function testDeleteWrongBookcaseIs404(): void
    {
        $this->loginAsUser();
        $other = BookcaseFactory::createOne();
        $image = ImageFactory::createOne();
        $this->client->request('DELETE', '/api/bookcase/' . $other->id . '/image/' . $image->id);
        $this->assertResponseStatusCodeSame(404);
    }
}
