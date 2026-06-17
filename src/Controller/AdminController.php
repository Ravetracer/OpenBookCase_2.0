<?php

namespace App\Controller;

use App\Entity\ApiApplication;
use App\Entity\User;
use App\Repository\ApiApplicationRepository;
use App\Repository\ApiUsageLogRepository;
use App\Repository\MessageRepository;
use App\Service\ApiApplicationService;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;

/**
 * Administrative back office (ROLE_ADMIN). Phase 1 hosts the API-access
 * application review; future admin tooling (usage logs, …) slots in here.
 * Access is double-gated: this attribute + the `^/admin` access_control rule.
 */
#[Route('/admin', name: 'app_admin_')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    private const USAGE_PER_PAGE = 50;

    public function __construct(
        private readonly ApiApplicationRepository $applications,
        private readonly MessageRepository $messages,
        private readonly ApiApplicationService $applicationService,
        private readonly ApiUsageLogRepository $usageLogs,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'pendingCount' => $this->applications->countPending(),
        ]);
    }

    #[Route('/api-usage', name: 'api_usage', methods: ['GET'])]
    public function apiUsage(Request $request): Response
    {
        $appId = (string) $request->query->get('application', '');
        $application = ($appId !== '' && Ulid::isValid($appId)) ? $this->applications->find($appId) : null;

        $filters = [
            'application' => $application,
            'q' => trim((string) $request->query->get('q', '')),
            'method' => trim((string) $request->query->get('method', '')),
        ];

        $page = max(1, $request->query->getInt('page', 1));
        $total = $this->usageLogs->countFiltered($filters);

        return $this->render('admin/api_usage.html.twig', [
            'logs' => $this->usageLogs->findFilteredPaginated($filters, $page, self::USAGE_PER_PAGE),
            'applications' => $this->applications->findAllNewestFirst(),
            'application' => $application,
            'q' => $filters['q'],
            'method' => $filters['method'],
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::USAGE_PER_PAGE)),
            'total' => $total,
        ]);
    }

    #[Route('/api-applications', name: 'api_applications', methods: ['GET'])]
    public function apiApplications(): Response
    {
        return $this->render('admin/api_applications.html.twig', [
            'applications' => $this->applications->findAllNewestFirst(),
        ]);
    }

    #[Route('/api-applications/{application}', name: 'api_application', methods: ['GET'])]
    public function apiApplication(ApiApplication $application): Response
    {
        return $this->render('admin/api_application.html.twig', [
            'application' => $application,
            'thread' => $this->messages->findThreadForApplication($application),
        ]);
    }

    #[Route('/api-applications/{application}/approve', name: 'api_approve', methods: ['POST'])]
    public function approve(Request $request, ApiApplication $application): RedirectResponse
    {
        if ($this->validToken($request, 'admin_api_decision', $application)) {
            $this->applicationService->approve($application, $this->admin());
            $this->addFlash('success', 'admin.api.flash_approved');
        }

        return $this->redirectToRoute('app_admin_api_application', ['application' => $application->id]);
    }

    #[Route('/api-applications/{application}/deny', name: 'api_deny', methods: ['POST'])]
    public function deny(Request $request, ApiApplication $application): RedirectResponse
    {
        $reason = trim((string) $request->request->get('reason'));
        if ($this->validToken($request, 'admin_api_decision', $application)) {
            if ($reason === '') {
                $this->addFlash('error', 'admin.api.flash_reason_required');
            } else {
                $this->applicationService->deny($application, $this->admin(), $reason);
                $this->addFlash('success', 'admin.api.flash_denied');
            }
        }

        return $this->redirectToRoute('app_admin_api_application', ['application' => $application->id]);
    }

    #[Route('/api-applications/{application}/revoke', name: 'api_revoke', methods: ['POST'])]
    public function revoke(Request $request, ApiApplication $application): RedirectResponse
    {
        $reason = trim((string) $request->request->get('reason'));
        if ($this->validToken($request, 'admin_api_decision', $application)) {
            if ($reason === '') {
                $this->addFlash('error', 'admin.api.flash_reason_required');
            } else {
                $this->applicationService->revoke($application, $this->admin(), $reason);
                $this->addFlash('success', 'admin.api.flash_revoked');
            }
        }

        return $this->redirectToRoute('app_admin_api_application', ['application' => $application->id]);
    }

    #[Route('/api-applications/{application}/message', name: 'api_message', methods: ['POST'])]
    public function message(Request $request, ApiApplication $application): RedirectResponse
    {
        $body = trim((string) $request->request->get('body'));
        if ($this->validToken($request, 'admin_api_message', $application)) {
            if ($body === '') {
                $this->addFlash('error', 'admin.api.flash_message_empty');
            } else {
                $this->applicationService->postMessage($application, $this->admin(), $body);
                $this->addFlash('success', 'admin.api.flash_message_sent');
            }
        }

        return $this->redirectToRoute('app_admin_api_application', ['application' => $application->id]);
    }

    private function validToken(Request $request, string $id, ApiApplication $application): bool
    {
        $valid = $this->isCsrfTokenValid($id . '_' . $application->id, (string) $request->request->get('_token'));
        if (!$valid) {
            $this->addFlash('error', 'flash.invalid_token');
        }

        return $valid;
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
