<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\BookcaseType;
use App\Repository\BookcaseRepository;
use App\Service\BookcaseListService;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class IndexController extends AbstractController
{
    public function __construct(
        private readonly BookcaseListService $listService,
    ) {
    }

    // `/map` is served directly (HTTP 200, no redirect) so the many legacy
    // backlinks to https://openbookcase.de/map keep resolving and pass their
    // link equity. base.html.twig emits a rel=canonical pointing at `/` so
    // Google consolidates both URLs into one ranking signal (no duplicate
    // content), while the inbound links still count.
    #[Route('/', name: 'app_index')]
    #[Route('/map', name: 'app_index_map')]
    #[Route('/index', name: 'app_index_legacy')]
    public function index(#[CurrentUser] ?User $user): Response
    {
        $form = $this->createForm(BookcaseType::class);

        return $this->render('index/index.html.twig', [
            'controller_name' => 'IndexController',
            'bookcase_form' => $form->createView(),
            'watchedIds' => $this->listService->watchedIds($user),
        ]);
    }

    #[Route('/bookcase/{bookcase}', name: 'app_bookcase_show')]
    public function showBookcase(string $bookcase, BookcaseRepository $bookcaseRepository, #[CurrentUser] ?User $user): Response
    {
        // Shareable deep link: render the map, then let the frontend center on the
        // entry and open its detail dialog. Unknown ids just fall back to the map.
        return $this->render('index/index.html.twig', [
            'initialBookcase' => $bookcaseRepository->findOneWithRelations($bookcase),
            'watchedIds' => $this->listService->watchedIds($user),
        ]);
    }

    /**
     * Short share link target (https://obc.onl/{code} → /s/{code}): renders the
     * same map deep link as app_bookcase_show.
     */
    #[Route('/s/{code}', name: 'app_short_link', requirements: ['code' => '[0-9A-Za-z]+'])]
    public function shortLink(string $code, #[CurrentUser] ?User $user): Response
    {
        return $this->render('index/index.html.twig', [
            'initialBookcase' => $this->listService->findByShortCode($code),
            'watchedIds' => $this->listService->watchedIds($user),
        ]);
    }

    #[Route('/list', name: 'app_list')]
    public function list(Request $request, #[CurrentUser] ?User $user): Response
    {
        return $this->render('index/list.html.twig', $this->listService->listView($request, $user));
    }

    /**
     * Just the table + pagination, for the live (AJAX) search/sort/paginate
     * swaps done by the `list` Stimulus controller.
     */
    #[Route('/list/fragment', name: 'app_list_fragment')]
    public function listFragment(Request $request, #[CurrentUser] ?User $user): Response
    {
        return $this->render('index/_list_table.html.twig', $this->listService->listView($request, $user));
    }
}
