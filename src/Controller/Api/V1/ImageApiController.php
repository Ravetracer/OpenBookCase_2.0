<?php

namespace App\Controller\Api\V1;

use App\Entity\Bookcase;
use App\Entity\Image;
use App\Entity\User;
use App\Service\ImageService;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Public API v1 — images of a bookcase. Reading the list is open; uploading needs
 * the `images.write` scope (role ROLE_OAUTH2_IMAGES.WRITE) and is attributed to the
 * token's user. Files are served statically from /images/{filename}.
 */
#[Route('/api/v1/bookcases/{bookcase}/images', name: 'api_v1_images_')]
class ImageApiController extends AbstractController
{
    private const MAX_IMAGES = 5;
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ImageService $imageService,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Bookcase $bookcase, Request $request): JsonResponse
    {
        $base = $request->getSchemeAndHttpHost();
        $images = [];
        foreach ($bookcase->images as $image) {
            $images[] = [
                'id' => (string) $image->id,
                'author' => $image->author,
                'altText' => $image->altText,
                'url' => $base . '/images/' . $image->filename,
                'thumbnailUrl' => $image->filenameThumbnail ? $base . '/images/' . $image->filenameThumbnail : null,
            ];
        }

        return new JsonResponse(['images' => $images]);
    }

    #[Route('', name: 'upload', methods: ['POST'])]
    #[IsGranted('ROLE_OAUTH2_IMAGES.WRITE')]
    public function upload(Bookcase $bookcase, Request $request): JsonResponse
    {
        if ($bookcase->images->count() >= self::MAX_IMAGES) {
            return new JsonResponse(['error' => sprintf('A bookcase can have at most %d images.', self::MAX_IMAGES)], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $file = $request->files->get('imageFile');
        $author = trim((string) $request->request->get('author', ''));
        if (!$file) {
            return new JsonResponse(['error' => 'No image file (field "imageFile").'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($author === '') {
            return new JsonResponse(['error' => 'An "author" is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!in_array($file->getMimeType(), self::ALLOWED_MIME, true)) {
            return new JsonResponse(['error' => 'Unsupported image type.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $altText = trim((string) $request->request->get('altText', ''));

        /** @var User $user */
        $user = $this->getUser();

        $image = new Image();
        $image->bookcase = $bookcase;
        $image->uploadedBy = $user;
        $image->author = $author;
        $image->altText = $altText !== '' ? $altText : null;
        $image->setImageFile($file);

        $this->entityManager->persist($image);
        $this->entityManager->flush(); // VichUploader writes the file + filename/size

        $this->imageService->processUpload($image);
        $this->entityManager->flush(); // persist the thumbnail filename

        return new JsonResponse([
            'id' => (string) $image->id,
            'author' => $image->author,
            'altText' => $image->altText,
            'url' => $request->getSchemeAndHttpHost() . '/images/' . $image->filename,
        ], Response::HTTP_CREATED);
    }
}
