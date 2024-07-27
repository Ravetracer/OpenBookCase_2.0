<?php

namespace App\Controller;

use App\Entity\Bookcase;

use Doctrine\ORM\EntityManagerInterface;

use FOS\RestBundle\Controller\Annotations\QueryParam;
use FOS\RestBundle\Request\ParamFetcher;

use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerInterface;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/api/bookcase', name: 'api_bookcase_')]
class BookcaseController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SerializerInterface $serializer,
    )
    {
    }

    #[Route('/', name: 'retrieve')]
    #[QueryParam(name: 'latMin', requirements: '[-]?[0-9]+(\.[0-9]+)?', description: 'Minimum latitude to search for', strict: true, nullable: false, allowBlank: false)]
    #[QueryParam(name: 'latMax', requirements: '[-]?[0-9]+(\.[0-9]+)?', description: 'Maximum latitude to search for', strict: true, nullable: false, allowBlank: false)]
    #[QueryParam(name: 'lonMin', requirements: '[-]?[0-9]+(\.[0-9]+)?', description: 'Minimum longitude to search for', strict: true, nullable: false, allowBlank: false)]
    #[QueryParam(name: 'lonMax', requirements: '[-]?[0-9]+(\.[0-9]+)?', description: 'Maximum longitude to search for', strict: true, nullable: false, allowBlank: false)]
    public function retrieveBookCases(ParamFetcher $paramFetcher): JsonResponse
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('bc')
            ->from('App:Bookcase', 'bc')
            ->add('where', $qb->expr()->andX(
                $qb->expr()->between('bc.position.latitude', (float) $paramFetcher->get('latMin'), (float) $paramFetcher->get('latMax')),
                $qb->expr()->between('bc.position.longitude', (float) $paramFetcher->get('lonMin'), (float) $paramFetcher->get('lonMax'))
            )
        );

        $bookCases = $qb->getQuery()->execute();

        return new JsonResponse($this->serializer->serialize($bookCases, 'json', SerializationContext::create()->setGroups(['bookcase'])), Response::HTTP_OK, json: true);
    }

    #[Route('/{bookcase}', name: 'retrieve_single')]
    public function retrieveBookcaseDetails(Bookcase $bookcase): JsonResponse
    {
        return new JsonResponse($this->serializer->serialize($bookcase, 'json', SerializationContext::create()->setGroups(['bookcase', 'bookcase_detail', 'caretaker', 'address', 'images'])), Response::HTTP_OK, json: true);
    }
}
