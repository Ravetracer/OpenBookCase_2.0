<?php declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Bookcase;
use App\Entity\Image;
use App\Entity\User;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\ImageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\Api\OAuthApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * File-upload and file-handling attacks against the web image upload
 * (POST /api/bookcase/{id}/image) and the API v1 upload
 * (POST /api/v1/bookcases/{id}/images), plus rotate/alt/delete on images.
 * Assertions are made on the real filesystem under public/images.
 */
final class UploadSecurityTest extends OAuthApiTestCase
{
    private const STORED_NAME = '/^bookcase_(?:[0-9A-HJKMNP-TV-Z]{26})?_[0-9A-HJKMNP-TV-Z]{26}\.(jpe?g|png|gif|webp)$/';
    private const PHP_PAYLOAD = '<?php system($_GET["c"]); ?>';

    /** @var string[] absolute paths created during a test, removed in tearDown */
    private array $cleanup = [];

    private string $projectDir;
    private string $uploadDir;
    private ?Bookcase $bookcase = null;
    private ?User $uploader = null;
    private ?string $token = null;
    private string $htaccessHash;
    private string $imagesHtaccessHash;

    protected function setUp(): void
    {
        parent::setUp();
        if (!\function_exists('imagejpeg')) {
            $this->markTestSkipped('GD is required for upload security tests.');
        }
        $this->projectDir = (string) static::getContainer()->getParameter('kernel.project_dir');
        $this->uploadDir = $this->projectDir . '/public/images/';
        $this->htaccessHash = (string) md5_file($this->projectDir . '/public/.htaccess');
        $this->imagesHtaccessHash = (string) md5_file($this->projectDir . '/public/images/.htaccess');
    }

    protected function tearDown(): void
    {
        // DB rows are rolled back by DAMA, files are not: remove every file that
        // belongs to an image row of this test's bookcase, plus tracked temp files.
        if ($this->bookcase !== null) {
            foreach ($this->storedFilenames() as $name) {
                $this->cleanup[] = $this->uploadDir . $name;
            }
        }
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    public static function channels(): iterable
    {
        yield 'web' => ['web'];
        yield 'api' => ['api'];
    }

    // ---------------------------------------------------------------------
    // Webshells / polyglots
    // ---------------------------------------------------------------------

    public static function hostileNames(): iterable
    {
        $names = [
            'php' => 'shell.php',
            'phtml' => 'shell.phtml',
            'phar' => 'shell.phar',
            'double-ext' => 'shell.php.jpg',
            'htaccess' => '.htaccess',
            'traversal' => '../../../public/evil.php',
            'backslash-traversal' => '..\\..\\evil.php',
            'absolute' => '/tmp/evil.php',
            'encoded-null' => 'evil.php%00.jpg',
            'raw-null' => "evil.php\0.jpg",
        ];
        foreach (['web', 'api'] as $channel) {
            foreach ($names as $label => $name) {
                yield "$channel $label" => [$channel, $name];
            }
        }
    }

    /** A real image under a hostile client name/MIME is stored under a server-generated image name only. */
    #[DataProvider('hostileNames')]
    public function testClientFilenameIsNeverTrusted(string $channel, string $clientName): void
    {
        $status = $this->upload($channel, $this->file($this->jpegBytes(), $clientName, 'image/jpeg'));

        $this->assertLessThan(500, $status);
        foreach ($this->storedFilenames() as $name) {
            $this->assertMatchesRegularExpression(self::STORED_NAME, $name);
            $this->assertStringNotContainsStringIgnoringCase('shell', $name);
            $this->assertStringNotContainsStringIgnoringCase('evil', $name);
            $this->assertStringNotContainsString('php', $name);
        }
        $this->assertNoStrayFiles();
    }

    public static function polyglots(): iterable
    {
        foreach (['web', 'api'] as $channel) {
            foreach (['jpeg-appended', 'jpeg-comment', 'png-appended', 'gif-appended'] as $kind) {
                yield "$channel $kind" => [$channel, $kind];
            }
        }
    }

    /** A valid image carrying PHP must be rejected or re-encoded so the payload never lands on disk. */
    #[DataProvider('polyglots')]
    public function testPolyglotImageNeverStoresPhp(string $channel, string $kind): void
    {
        $bytes = match ($kind) {
            'jpeg-appended' => $this->jpegBytes() . self::PHP_PAYLOAD,
            'jpeg-comment' => $this->insertSegment($this->jpegBytes(), "\xFF\xFE", self::PHP_PAYLOAD),
            'png-appended' => $this->gdBytes('imagepng') . self::PHP_PAYLOAD,
            'gif-appended' => $this->gdBytes('imagegif') . self::PHP_PAYLOAD,
        };

        $status = $this->upload($channel, $this->file($bytes, 'shell.php.jpg', 'image/jpeg'));

        $this->assertLessThan(500, $status);
        $this->assertStoredFilesAreCleanImages();
    }

    public static function fakeImages(): iterable
    {
        foreach (['web', 'api'] as $channel) {
            yield "$channel php-only" => [$channel, self::PHP_PAYLOAD, 'shell.jpg', 'image/jpeg'];
            yield "$channel jpeg-magic+php" => [$channel, "\xFF\xD8\xFF\xE0" . self::PHP_PAYLOAD, 'shell.jpg', 'image/jpeg'];
            yield "$channel gif-magic+php" => [$channel, "GIF89a\x01\x00\x01\x00" . self::PHP_PAYLOAD, 'shell.gif', 'image/gif'];
            yield "$channel svg-script" => [$channel, '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(document.cookie)</script></svg>', 'x.svg', 'image/svg+xml'];
            yield "$channel html" => [$channel, '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>', 'x.html', 'text/html'];
            yield "$channel htaccess" => [$channel, "AddType application/x-httpd-php .jpg\n", '.htaccess', 'image/jpeg'];
            yield "$channel zero-byte" => [$channel, '', 'empty.jpg', 'image/jpeg'];
        }
    }

    /** Non-images (incl. SVG, HTML, magic-byte-prefixed PHP, empty files) are rejected cleanly and nothing is stored. */
    #[DataProvider('fakeImages')]
    public function testNonImageIsRejected(string $channel, string $bytes, string $clientName, string $clientMime): void
    {
        $status = $this->upload($channel, $this->file($bytes, $clientName, $clientMime));

        $this->assertStoredFilesAreCleanImages();
        $this->assertSame([], $this->storedFilenames(), 'a rejected upload must not leave a file in public/images');
        $this->assertSame(422, $status, 'non-image upload must be rejected with 422');
        $this->assertNoStrayFiles();
    }

    // ---------------------------------------------------------------------
    // Formats, corrupt data, decompression bombs
    // ---------------------------------------------------------------------

    public static function formats(): iterable
    {
        foreach (['web', 'api'] as $channel) {
            yield "$channel webp" => [$channel, 'webp', true];
            yield "$channel gif" => [$channel, 'gif', true];
            yield "$channel png" => [$channel, 'png', true];
            yield "$channel bmp" => [$channel, 'bmp', false];
            yield "$channel tiff" => [$channel, 'tiff', false];
        }
    }

    #[DataProvider('formats')]
    public function testFormatPolicy(string $channel, string $format, bool $allowed): void
    {
        $bytes = match ($format) {
            'webp' => $this->gdBytes('imagewebp'),
            'gif' => $this->gdBytes('imagegif'),
            'png' => $this->gdBytes('imagepng'),
            'bmp' => $this->gdBytes('imagebmp'),
            // Minimal little-endian TIFF: header + one-entry IFD (width=1).
            'tiff' => "II*\0\x08\0\0\0\x01\0\x00\x01\x03\0\x01\0\0\0\x01\0\0\0\0\0\0\0",
        };

        $status = $this->upload($channel, $this->file($bytes, 'photo.' . $format, 'image/' . $format));

        if ($allowed) {
            $this->assertSame(201, $status);
            $this->assertCount(1, $this->storedFilenames());
            $this->assertStoredFilesAreCleanImages();
        } else {
            $this->assertSame(422, $status);
            $this->assertSame([], $this->storedFilenames());
        }
    }

    /** A truncated JPEG (valid header, missing data) must not cause a 500 or leave a broken file behind. */
    #[DataProvider('channels')]
    public function testTruncatedJpegIsHandledSafely(string $channel): void
    {
        $jpeg = $this->jpegBytes(200, 200);
        $status = $this->upload($channel, $this->file(substr($jpeg, 0, (int) (strlen($jpeg) / 2)), 'cut.jpg', 'image/jpeg'));

        $this->assertLessThan(500, $status);
        $this->assertStoredFilesAreCleanImages();
    }

    /**
     * A ~70-byte PNG declaring 12000x12000 px. With the system libgd this makes the
     * PHP worker allocate hundreds of MB outside memory_limit before failing, so it
     * must be rejected by a pixel-count check before decoding.
     */
    #[DataProvider('channels')]
    public function testDecompressionBombIsRejectedBeforeDecoding(string $channel): void
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $png = "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', 12000, 12000, 8, 2, 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress(str_repeat("\0", 64)))
            . $chunk('IEND', '');

        $status = $this->upload($channel, $this->file($png, 'bomb.png', 'image/png'));

        $this->assertSame([], $this->storedFilenames(), 'a rejected upload must not leave a file in public/images');
        $this->assertSame(422, $status, 'oversized pixel dimensions must be rejected before decoding');
    }

    /**
     * Over the size cap. NB: the test client turns a file above upload_max_filesize
     * into an UPLOAD_ERR_INI_SIZE upload with an empty path, as PHP would in prod.
     */
    #[DataProvider('channels')]
    public function testOversizedUploadIsRejected(string $channel): void
    {
        $bytes = $this->jpegBytes() . str_repeat("\0", 8 * 1024 * 1024 + 1024);

        $this->assertSame(422, $this->upload($channel, $this->file($bytes, 'big.jpg', 'image/jpeg')));
        $this->assertSame([], $this->storedFilenames());
    }

    /** An upload PHP flagged as failed (e.g. over upload_max_filesize) must be rejected, not moved. */
    #[DataProvider('channels')]
    public function testFailedPhpUploadIsRejected(string $channel): void
    {
        $file = $this->file($this->jpegBytes(), 'x.jpg', 'image/jpeg', \UPLOAD_ERR_INI_SIZE);

        $this->assertSame(422, $this->upload($channel, $file));
        $this->assertSame([], $this->storedFilenames());
    }

    // ---------------------------------------------------------------------
    // EXIF / GPS privacy
    // ---------------------------------------------------------------------

    #[DataProvider('channels')]
    public function testGpsExifIsStrippedFromStoredFile(string $channel): void
    {
        if (!\function_exists('exif_read_data')) {
            $this->markTestSkipped('ext-exif is required to verify EXIF handling.');
        }
        $bytes = $this->insertSegment($this->jpegBytes(), "\xFF\xE1", "Exif\0\0" . $this->gpsTiff());
        $src = $this->tmp($bytes, '.jpg');
        $srcExif = @exif_read_data($src);
        $this->assertIsArray($srcExif);
        $this->assertArrayHasKey('GPSLatitude', $srcExif, 'precondition: crafted source carries GPS');

        $this->assertSame(201, $this->upload($channel, new UploadedFile($src, 'gps.jpg', 'image/jpeg', null, true)));

        [$name] = $this->storedFilenames();
        $stored = $this->uploadDir . $name;
        $exif = @exif_read_data($stored);
        $this->assertFalse(\is_array($exif) && isset($exif['GPSLatitude']), 'stored file must not retain GPS EXIF');
        $this->assertStringNotContainsString("Exif\0\0", (string) file_get_contents($stored));
    }

    // ---------------------------------------------------------------------
    // Malformed requests
    // ---------------------------------------------------------------------

    /** imageFile[] (an array where one file is expected) must be a clean 4xx, not a 500. */
    #[DataProvider('channels')]
    public function testFileArrayIsRejectedCleanly(string $channel): void
    {
        $status = $this->upload($channel, [
            $this->file($this->jpegBytes(), 'a.jpg', 'image/jpeg'),
            $this->file($this->jpegBytes(), 'b.jpg', 'image/jpeg'),
        ]);

        $this->assertGreaterThanOrEqual(400, $status);
        $this->assertLessThan(500, $status);
        $this->assertSame([], $this->storedFilenames());
    }

    #[DataProvider('channels')]
    public function testNonMultipartBodyIsRejected(string $channel): void
    {
        $status = $this->upload($channel, null, [], (string) json_encode(['imageFile' => base64_encode($this->jpegBytes()), 'author' => 'X']));

        $this->assertSame(422, $status);
        $this->assertSame([], $this->storedFilenames());
    }

    /** Extra fields cannot steer the stored name, owner or target bookcase. */
    #[DataProvider('channels')]
    public function testExtraFieldsCannotOverrideServerState(string $channel): void
    {
        $other = BookcaseFactory::createOne();
        $otherUser = UserFactory::createOne();

        $status = $this->upload($channel, $this->file($this->jpegBytes(), 'a.jpg', 'image/jpeg'), [
            'author' => 'Mallory',
            'filename' => '../../evil.php',
            'filenameThumbnail' => '../../evil2.php',
            'bookcase' => (string) $other->id,
            'uploadedBy' => (string) $otherUser->id,
            'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'imageSize' => '1',
        ]);
        $this->assertSame(201, $status);

        $images = $this->images();
        $this->assertCount(1, $images);
        $this->assertMatchesRegularExpression(self::STORED_NAME, $images[0]->filename);
        $this->assertNull($images[0]->filenameThumbnail);
        $this->assertSame((string) $this->uploader->id, (string) $images[0]->uploadedBy->id);
        $this->assertNotSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', (string) $images[0]->id);
        $this->assertSame(0, $this->em()->getRepository(Image::class)->count(['bookcase' => $other]));
        $this->assertNoStrayFiles();
    }

    // ---------------------------------------------------------------------
    // Rotate / alt / delete
    // ---------------------------------------------------------------------

    public static function junkDirections(): iterable
    {
        yield 'traversal' => ['../../'];
        yield 'suffix' => ['cw/../../evil'];
        yield 'empty' => [''];
        yield 'numeric' => ['90'];
        yield 'uppercase' => ['CW'];
        yield 'null-byte' => ["cw\0"];
    }

    #[DataProvider('junkDirections')]
    public function testRotateRejectsJunkDirection(string $direction): void
    {
        $this->assertSame(201, $this->upload('web', $this->file($this->jpegBytes(), 'a.jpg', 'image/jpeg')));
        [$image] = $this->images();
        $before = md5_file($this->uploadDir . $image->filename);

        $this->client->request('POST', $this->imageUrl($image) . '/rotate', ['direction' => $direction]);

        $this->assertResponseStatusCodeSame(400);
        $this->assertSame($before, md5_file($this->uploadDir . $image->filename), 'file must be untouched');
    }

    public function testRotateRejectsArrayDirection(): void
    {
        $this->assertSame(201, $this->upload('web', $this->file($this->jpegBytes(), 'a.jpg', 'image/jpeg')));
        [$image] = $this->images();

        $this->client->request('POST', $this->imageUrl($image) . '/rotate', ['direction' => ['cw']]);

        $status = $this->client->getResponse()->getStatusCode();
        $this->assertGreaterThanOrEqual(400, $status);
        $this->assertLessThan(500, $status);
    }

    /** alt text is a VARCHAR(255): overlong input must be rejected or bounded, never stored raw. */
    public function testAltTextLengthIsBounded(): void
    {
        $this->loginAsUploader();
        $image = ImageFactory::createOne(['bookcase' => $this->bookcase, 'altText' => null]);

        $this->client->request('POST', $this->imageUrl($image) . '/alt', ['altText' => str_repeat('A', 5000)]);

        $status = $this->client->getResponse()->getStatusCode();
        $this->assertLessThan(500, $status);
        $this->em()->clear();
        $stored = $this->em()->getRepository(Image::class)->find($image->id)->altText;
        $this->assertLessThanOrEqual(255, mb_strlen((string) $stored), 'alt text must not exceed the 255-char column');
    }

    /** Same bound on upload: author/altText beyond the column length must not be stored raw. */
    #[DataProvider('channels')]
    public function testUploadTextFieldsAreBounded(string $channel): void
    {
        $status = $this->upload($channel, $this->file($this->jpegBytes(), 'a.jpg', 'image/jpeg'), [
            'author' => str_repeat('B', 5000),
            'altText' => str_repeat('C', 5000),
        ]);

        $this->assertLessThan(500, $status);
        foreach ($this->images() as $image) {
            $this->assertLessThanOrEqual(255, mb_strlen((string) $image->author));
            $this->assertLessThanOrEqual(255, mb_strlen((string) $image->altText));
        }
    }

    public function testDeleteRemovesFileFromDisk(): void
    {
        $this->assertSame(201, $this->upload('web', $this->file($this->jpegBytes(), 'a.jpg', 'image/jpeg')));
        [$image] = $this->images();
        $path = $this->uploadDir . $image->filename;
        $this->assertFileExists($path);

        $this->client->request('DELETE', $this->imageUrl($image));

        $this->assertResponseIsSuccessful();
        $this->assertFileDoesNotExist($path, 'deleting an image must not leave an orphan file');
    }

    public static function craftedImageParams(): iterable
    {
        yield 'encoded traversal' => ['..%2F..%2F..%2Fcomposer.json'];
        yield 'dot-dot' => ['..'];
        yield 'filename-like' => ['bookcase_x.jpg'];
    }

    #[DataProvider('craftedImageParams')]
    public function testDeleteWithCraftedImageParamIs404(string $param): void
    {
        $this->loginAsUploader();
        $composerHash = md5_file($this->projectDir . '/composer.json');

        $this->client->request('DELETE', '/api/bookcase/' . $this->bookcase->id . '/image/' . $param);

        $this->assertResponseStatusCodeSame(404);
        $this->assertSame($composerHash, md5_file($this->projectDir . '/composer.json'));
    }

    /**
     * Defence in depth: even a tampered DB filename (e.g. "../../var/x") must not
     * let delete/rotate reach a file outside public/images.
     */
    public static function fileActions(): iterable
    {
        yield 'delete' => ['delete'];
        yield 'rotate' => ['rotate'];
    }

    #[DataProvider('fileActions')]
    public function testTamperedDbFilenameCannotEscapeUploadDir(string $action): void
    {
        $this->loginAsUploader();
        $canaryName = 'obc_upload_canary_' . bin2hex(random_bytes(6)) . '.jpg';
        $canary = $this->projectDir . '/var/' . $canaryName;
        file_put_contents($canary, $this->jpegBytes());
        $this->cleanup[] = $canary;
        $hash = md5_file($canary);

        $image = ImageFactory::createOne(['bookcase' => $this->bookcase, 'filename' => '../../var/' . $canaryName]);

        if ($action === 'delete') {
            $this->client->request('DELETE', $this->imageUrl($image));
        } else {
            $this->client->request('POST', $this->imageUrl($image) . '/rotate', ['direction' => 'cw']);
        }

        $this->assertFileExists($canary, 'a file outside public/images must never be deleted');
        $this->assertSame($hash, md5_file($canary), 'a file outside public/images must never be rewritten');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function loginAsUploader(): void
    {
        $this->uploader ??= UserFactory::createOne();
        $this->bookcase ??= BookcaseFactory::createOne();
        $this->client->loginUser($this->uploader);
    }

    /**
     * POST to the chosen upload endpoint and return the status code.
     *
     * @param UploadedFile|UploadedFile[]|null $file
     * @param array<string, mixed> $params
     */
    private function upload(string $channel, UploadedFile|array|null $file, array $params = ['author' => 'Mallory'], ?string $rawBody = null): int
    {
        $this->loginAsUploader();
        $files = $file === null ? [] : ['imageFile' => $file];
        $server = $rawBody !== null ? ['CONTENT_TYPE' => 'application/json'] : [];

        if ($channel === 'api') {
            $this->token ??= $this->tokenFor($this->uploader, ['images.write']);
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token;
            $url = '/api/v1/bookcases/' . $this->bookcase->id . '/images';
        } else {
            $url = '/api/bookcase/' . $this->bookcase->id . '/image';
        }

        $this->client->request('POST', $url, $rawBody !== null ? [] : $params, $files, $server, $rawBody);

        return $this->client->getResponse()->getStatusCode();
    }

    private function imageUrl(Image $image): string
    {
        return '/api/bookcase/' . $this->bookcase->id . '/image/' . $image->id;
    }

    /** @return Image[] rows of this test's bookcase (fresh from the DB) */
    private function images(): array
    {
        $em = $this->em();
        $em->clear();

        return $em->getRepository(Image::class)->findBy(['bookcase' => $this->bookcase]);
    }

    /** @return string[] filenames of this test's images that exist on disk */
    private function storedFilenames(): array
    {
        $names = [];
        foreach ($this->images() as $image) {
            if ($image->filename !== null && is_file($this->uploadDir . $image->filename)) {
                $names[] = $image->filename;
            }
        }

        return $names;
    }

    private function assertStoredFilesAreCleanImages(): void
    {
        foreach ($this->storedFilenames() as $name) {
            $this->assertMatchesRegularExpression(self::STORED_NAME, $name);
            $bytes = (string) file_get_contents($this->uploadDir . $name);
            $this->assertStringNotContainsString('<?php', $bytes, "$name must not contain PHP");
            $this->assertStringNotContainsString('<?=', $bytes, "$name must not contain PHP");
            $this->assertNotFalse(@getimagesize($this->uploadDir . $name), "$name must be a decodable image");
        }
    }

    /** Nothing named after the hostile client names appeared outside public/images. */
    private function assertNoStrayFiles(): void
    {
        foreach ([$this->projectDir . '/public', $this->projectDir, \dirname($this->projectDir), $this->uploadDir, '/tmp'] as $dir) {
            foreach (['evil.php', 'shell.php', 'shell.phtml', 'shell.phar', 'evil.php.jpg'] as $name) {
                $this->assertFileDoesNotExist($dir . '/' . $name);
            }
        }
        // The shipped no-script-execution rules in public/images must be left untouched.
        $this->assertSame($this->imagesHtaccessHash, md5_file($this->uploadDir . '.htaccess'));
        $this->assertSame($this->htaccessHash, md5_file($this->projectDir . '/public/.htaccess'));
    }

    private function tmp(string $bytes, string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'obc_sec_') . $suffix;
        file_put_contents($path, $bytes);
        $this->cleanup[] = $path;

        return $path;
    }

    private function file(string $bytes, string $clientName, string $clientMime, ?int $error = null): UploadedFile
    {
        return new UploadedFile($this->tmp($bytes, '.upload'), $clientName, $clientMime, $error, true);
    }

    private function jpegBytes(int $w = 10, int $h = 10): string
    {
        return $this->gdBytes('imagejpeg', $w, $h);
    }

    private function gdBytes(string $encoder, int $w = 10, int $h = 10): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, (int) imagecolorallocate($im, 200, 120, 40));
        ob_start();
        $encoder($im);

        return (string) ob_get_clean();
    }

    /** Insert a JPEG marker segment right after SOI. */
    private function insertSegment(string $jpeg, string $marker, string $payload): string
    {
        return substr($jpeg, 0, 2) . $marker . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
    }

    /** Minimal big-endian TIFF block: IFD0 -> GPS IFD with GPSLatitudeRef "N" + GPSLatitude 52/30/0. */
    private function gpsTiff(): string
    {
        $tiff = "MM\0\x2A" . pack('N', 8);
        $tiff .= pack('n', 1) . pack('nnNN', 0x8825, 4, 1, 26) . pack('N', 0);          // IFD0 @8, 18 bytes
        $tiff .= pack('n', 2)                                                          // GPS IFD @26
            . pack('nnN', 0x0001, 2, 2) . "N\0\0\0"
            . pack('nnNN', 0x0002, 5, 3, 56)
            . pack('N', 0);
        $tiff .= pack('NNNNNN', 52, 1, 30, 1, 0, 1);                                  // rationals @56

        return $tiff;
    }
}
