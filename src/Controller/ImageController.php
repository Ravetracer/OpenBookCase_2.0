<?php

namespace App\Controller;

use App\Entity\Bookcase;
use App\Entity\Image;
use App\Service\ImageService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/api/bookcase/{bookcase}/image', name: 'api_image_')]
#[IsGranted('ROLE_USER')]
class ImageController extends AbstractController
{
    private const MAX_IMAGES = 5;
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ImageService $imageService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'upload', methods: ['POST'])]
    public function upload(Bookcase $bookcase, Request $request): JsonResponse
    {
        if ($bookcase->images->count() >= self::MAX_IMAGES) {
            return new JsonResponse(
                ['error' => $this->translator->trans('flash.max_images', ['%count%' => self::MAX_IMAGES])],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $file = $request->files->get('imageFile');
        $author = trim($request->request->get('author', ''));

        if (!$file) {
            return new JsonResponse(['error' => $this->translator->trans('flash.no_file')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ($author === '') {
            return new JsonResponse(['error' => $this->translator->trans('flash.author_required')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!in_array($file->getMimeType(), self::ALLOWED_MIME, true)) {
            return new JsonResponse(['error' => $this->translator->trans('flash.invalid_image_type')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $altText = trim($request->request->get('altText', ''));

        $image = new Image();
        $image->bookcase = $bookcase;
        $image->uploadedBy = $this->getUser();
        $image->author = $author;
        $image->altText = $altText !== '' ? $altText : null;
        $image->setImageFile($file);

        $this->entityManager->persist($image);
        $this->entityManager->flush(); // VichUploader writes the file and sets filename/imageSize here

        $this->imageService->processUpload($image);
        $this->entityManager->flush(); // persist filenameThumbnail

        return new JsonResponse([
            'id' => (string) $image->id,
            'filename' => $image->filename,
            'author' => $image->author,
            'altText' => $image->altText,
        ], Response::HTTP_CREATED);
    }

    /** Update an existing image's alt text (screen-reader description). */
    #[Route('/{image}/alt', name: 'alt', methods: ['POST'])]
    public function updateAlt(Bookcase $bookcase, Image $image, Request $request): JsonResponse
    {
        if ((string) $image->bookcase->id !== (string) $bookcase->id) {
            throw $this->createNotFoundException();
        }

        $altText = trim($request->request->get('altText', ''));
        $image->altText = $altText !== '' ? $altText : null;
        $this->entityManager->flush();

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
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/{image}', name: 'delete', methods: ['DELETE'])]
    public function delete(Bookcase $bookcase, Image $image): JsonResponse
    {
        if ((string) $image->bookcase->id !== (string) $bookcase->id) {
            throw $this->createNotFoundException();
        }

        $this->entityManager->remove($image);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true]);
    }
}
