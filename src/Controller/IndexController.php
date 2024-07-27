<?php

namespace App\Controller;

use App\Form\BookcaseType;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class IndexController extends AbstractController
{
    #[Route('/', name: 'app_index')]
    public function index(): Response
    {
        $form = $this->createForm(BookcaseType::class);

        return $this->render('index/index.html.twig', [
            'controller_name' => 'IndexController',
            'bookcase_form' => $form->createView(),
        ]);
    }
}
