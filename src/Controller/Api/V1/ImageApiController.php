<?php

namespace App\Controller\Api\V1;

use App\Entity\Bookcase;
use App\Entity\User;
use App\Http\ApiProblem;
use App\Service\ImageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Public API v1 — images of a bookcase. Reading the list is open; uploading needs
 * the `images.write` scope (role ROLE_OAUTH2_IMAGES.WRITE) and is attributed to the
 * token's user. Files are served statically from /images/{filename}.
 */
#[Route('/api/v1/bookcases/{bookcase}/images', name: 'api_v1_images_')]
class ImageApiController extends AbstractController
{
    public function __construct(
        private readonly ImageService $imageService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Bookcase $bookcase, Request $request): JsonResponse
    {
        $base = $request->getSchemeAndHttpHost();
        $images = [];
        foreach ($bookcase->images as $image) {
            $images[] = $this->imageService->toApiArray($image, $base);
        }

        return new JsonResponse(['images' => $images]);
    }

    #[Route('', name: 'upload', methods: ['POST'])]
    #[IsGranted('ROLE_OAUTH2_IMAGES.WRITE')]
    public function upload(Bookcase $bookcase, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if ($this->imageService->isFull($bookcase)) {
            return new ApiProblem(sprintf('A bookcase can have at most %d images.', ImageService::MAX_IMAGES), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $file = $request->files->get('imageFile');
        $author = trim((string) $request->request->get('author', ''));
        $altText = trim((string) $request->request->get('altText', ''));
        if (!$file) {
            return new ApiProblem('No image file (field "imageFile").', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($author === '') {
            return new ApiProblem('An "author" is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $image = $this->imageService->upload($bookcase, $user, $file, $author, $altText);
        if (is_string($image)) {
            return new ApiProblem(
                $image === 'flash.text_too_long'
                    ? sprintf('"author" and "altText" may be at most %d characters.', ImageService::MAX_TEXT_LENGTH)
                    : $this->translator->trans($image, ImageService::ERROR_PARAMS, null, 'en'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return new JsonResponse([
            'id' => (string) $image->id,
            'author' => $image->author,
            'altText' => $image->altText,
            'url' => $request->getSchemeAndHttpHost() . '/images/' . $image->filename,
        ], Response::HTTP_CREATED);
    }
}
