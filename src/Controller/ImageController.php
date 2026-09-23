<?php

namespace App\Controller;

use App\Entity\Bookcase;
use App\Entity\Image;
use App\Entity\User;
use App\Service\ImageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/bookcase/{bookcase}/image', name: 'api_image_')]
#[IsGranted('ROLE_USER')]
class ImageController extends AbstractController
{
    public function __construct(
        private readonly ImageService $imageService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'upload', methods: ['POST'])]
    public function upload(Bookcase $bookcase, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $result = 'flash.max_images';
        if (!$this->imageService->isFull($bookcase)) {
            $file = $request->files->get('imageFile');
            $author = trim((string) $request->request->get('author', ''));
            $altText = trim((string) $request->request->get('altText', ''));
            $result = match (true) {
                !$file => 'flash.no_file',
                $author === '' => 'flash.author_required',
                default => $this->imageService->upload($bookcase, $user, $file, $author, $altText),
            };
        }
        if (is_string($result)) {
            return new JsonResponse(
                ['error' => $this->translator->trans($result, ImageService::ERROR_PARAMS)],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return new JsonResponse([
            'id' => (string) $result->id,
            'filename' => $result->filename,
            'author' => $result->author,
            'altText' => $result->altText,
        ], Response::HTTP_CREATED);
    }

    /** Update an existing image's alt text (screen-reader description). */
    #[Route('/{image}/alt', name: 'alt', methods: ['POST'])]
    public function updateAlt(Bookcase $bookcase, Image $image, Request $request): JsonResponse
    {
        if ((string) $image->bookcase->id !== (string) $bookcase->id) {
            throw $this->createNotFoundException();
        }

        $altText = trim((string) $request->request->get('altText', ''));
        if ($error = $this->imageService->updateAltText($image, $altText)) {
            return new JsonResponse(
                ['error' => $this->translator->trans($error, ImageService::ERROR_PARAMS)],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return new JsonResponse(['success' => true, 'altText' => $image->altText]);
    }

    #[Route('/{image}/rotate', name: 'rotate', methods: ['POST'])]
    public function rotate(Bookcase $bookcase, Image $image, Request $request): JsonResponse
    {
        if ((string) $image->bookcase->id !== (string) $bookcase->id) {
            throw $this->createNotFoundException();
        }

        $direction = $request->request->get('direction');
        if (!in_array($direction, ['cw', 'ccw'], true)) {
            return new JsonResponse(['error' => $this->translator->trans('flash.invalid_direction')], Response::HTTP_BAD_REQUEST);
        }

        $this->imageService->rotate($image, $direction === 'cw');

        return new JsonResponse(['success' => true]);
    }

    #[Route('/{image}', name: 'delete', methods: ['DELETE'])]
    public function delete(Bookcase $bookcase, Image $image): JsonResponse
    {
        if ((string) $image->bookcase->id !== (string) $bookcase->id) {
            throw $this->createNotFoundException();
        }

        $this->imageService->delete($image);

        return new JsonResponse(['success' => true]);
    }
}
