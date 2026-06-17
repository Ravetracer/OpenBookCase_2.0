<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StaticPageController extends AbstractController
{
    #[Route('/about', name: 'app_about')]
    public function about(): Response
    {
        return $this->render('static/about.html.twig');
    }

    #[Route('/imprint', name: 'app_imprint')]
    public function imprint(): Response
    {
        return $this->render('static/imprint.html.twig');
    }

    #[Route('/legal', name: 'app_legal')]
    public function legal(): Response
    {
        return $this->render('static/legal.html.twig');
    }

    #[Route('/licenses', name: 'app_licenses')]
    public function licenses(): Response
    {
        return $this->render('static/licenses.html.twig');
    }

    #[Route('/help', name: 'app_help')]
    public function help(): Response
    {
        return $this->render('static/help.html.twig');
    }

    #[Route('/changelog', name: 'app_changelog')]
    public function changelog(): Response
    {
        return $this->render('static/changelog.html.twig');
    }

    #[Route('/developers', name: 'app_developers')]
    public function developers(): Response
    {
        return $this->render('static/developers.html.twig');
    }
}
