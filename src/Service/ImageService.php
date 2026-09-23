<?php

namespace App\Service;

use App\Entity\Bookcase;
use App\Entity\Image;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Exceptions\ImageException;
use Intervention\Image\ImageManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Bookcase photos for the website and /api/v1: validated + re-encoded uploads,
 * alt text, rotation and deletion. Errors are returned as `flash.*` translation
 * keys; {@see self::ERROR_PARAMS} holds the placeholders those messages use.
 */
class ImageService
{
    public const MAX_IMAGES = 5;              // per bookcase
    public const MAX_BYTES = 8 * 1024 * 1024; // 8 MiB per upload
    public const MAX_TEXT_LENGTH = 255;       // author / altText column length
    public const ERROR_PARAMS = [
        '%count%' => self::MAX_IMAGES,
        '%max%' => self::MAX_TEXT_LENGTH,
        '%mb%' => self::MAX_BYTES / 1024 / 1024,
    ];
    private const MAX_LONG_SIDE = 1000;
    // GD allocates width*height*4+ bytes outside PHP's memory_limit, so a tiny
    // file declaring huge dimensions (decompression bomb) must be refused before decoding.
    private const MAX_PIXELS = 40_000_000;
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    private string $imageDir;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
    ) {
        $this->imageDir = $projectDir . '/public/images/';
    }

    public function isFull(Bookcase $bookcase): bool
    {
        return $bookcase->images->count() >= self::MAX_IMAGES;
    }

    /**
     * Validates the texts and the file, re-encodes it ({@see self::prepareUpload()})
     * and only then persists the image — nothing is stored on any error.
     *
     * @return Image|string the stored image, else the `flash.*` key of the error
     */
    public function upload(Bookcase $bookcase, User $uploader, mixed $file, string $author, string $altText): Image|string
    {
        if ($this->tooLong($author) || $this->tooLong($altText)) {
            return 'flash.text_too_long';
        }
        if ($error = $this->prepareUpload($file)) {
            return $error;
        }

        $image = new Image();
        $image->bookcase = $bookcase;
        $image->uploadedBy = $uploader;
        $image->author = $author;
        $image->altText = $altText !== '' ? $altText : null;
        $image->setImageFile($file);

        $this->entityManager->persist($image);
        $this->entityManager->flush(); // VichUploader moves the (already re-encoded) file and sets filename/imageSize here

        return $image;
    }

    /** @return string|null null on success, else the `flash.*` key of the error */
    public function updateAltText(Image $image, string $altText): ?string
    {
        if ($this->tooLong($altText)) {
            return 'flash.text_too_long';
        }
        $image->altText = $altText !== '' ? $altText : null;
        $this->entityManager->flush();

        return null;
    }

    public function rotate(Image $image, bool $clockwise): void
    {
        // basename(): a (tampered) filename can never point outside the upload dir.
        $path = $this->imageDir . basename((string) $image->filename);
        $img = (new ImageManager(new Driver()))->decodePath($path);

        // intervention/image v4: positive angle = clockwise
        $img->rotate($clockwise ? 90 : -90);
        $img->save($path);
    }

    public function delete(Image $image): void
    {
        $this->entityManager->remove($image);
        $this->entityManager->flush();
    }

    /** @return array<string, mixed> the /api/v1 representation; files are served from {$baseUrl}/images/ */
    public function toApiArray(Image $image, string $baseUrl): array
    {
        return [
            'id' => (string) $image->id,
            'author' => $image->author,
            'altText' => $image->altText,
            'url' => $baseUrl . '/images/' . $image->filename,
            'thumbnailUrl' => $image->filenameThumbnail ? $baseUrl . '/images/' . $image->filenameThumbnail : null,
        ];
    }

    /**
     * Validates an uploaded image and re-encodes it in place while it is still in
     * PHP's temp dir (auto-orient + downscale). Only a decoded-and-re-encoded image
     * ever reaches public/images, so appended/embedded payloads (e.g. PHP after a
     * GIF header) and metadata (EXIF GPS) are dropped.
     *
     * @return string|null null on success, else the `flash.*` translation key of the error
     */
    private function prepareUpload(mixed $file): ?string
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return 'flash.no_file';
        }
        if ($file->getSize() > self::MAX_BYTES) {
            return 'flash.image_too_large';
        }
        // Content-sniffed MIME (not the client-supplied one).
        $mime = $file->getMimeType();
        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            return 'flash.invalid_image_type';
        }
        $size = @getimagesize($file->getPathname());
        if ($size === false) {
            return 'flash.invalid_image_type';
        }
        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            return 'flash.image_dimensions_too_large';
        }

        try {
            $img = (new ImageManager(new Driver()))->decodePath($file->getPathname());
            $img->orient();
            $img->scaleDown(self::MAX_LONG_SIDE, self::MAX_LONG_SIDE);
            $img->encodeUsingMediaType($mime)->save($file->getPathname());
        } catch (ImageException|\ErrorException) {
            return 'flash.invalid_image_type';
        }
        clearstatcache(true, $file->getPathname());

        return null;
    }

    private function tooLong(string $value): bool
    {
        return mb_strlen($value) > self::MAX_TEXT_LENGTH;
    }
}
