<?php

namespace App\Controller;

use App\Entity\ApiApplication;
use App\Entity\User;
use App\Repository\ApiApplicationRepository;
use App\Repository\ApiUsageLogRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;
use App\Service\ApiApplicationService;
use App\Service\PasswordResetService;
use App\Service\UserAccountService;
use App\Service\UserAdminService;
use App\Service\UserDeletionService;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Administrative back office (ROLE_ADMIN): user management, API-access
 * application review and API usage logs.
 * Access is double-gated: this attribute + the `^/admin` access_control rule.
 *
 * Per-user and per-application actions carry a CSRF token bound to the target
 * (`<action>_<id>`), checked by #[IsCsrfTokenValid]; the bulk action checks its
 * token manually so a failure can return to the filtered list with a flash.
 */
#[Route('/admin', name: 'app_admin_')]
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    private const USAGE_PER_PAGE = 50;
    private const USERS_PER_PAGE = 50;

    public function __construct(
        private readonly ApiApplicationRepository $applications,
        private readonly MessageRepository $messages,
        private readonly ApiApplicationService $applicationService,
        private readonly ApiUsageLogRepository $usageLogs,
        private readonly UserRepository $users,
        private readonly UserAdminService $userAdmin,
        private readonly UserAccountService $accounts,
        private readonly UserDeletionService $userDeletion,
        private readonly PasswordResetService $passwordReset,
        private readonly EmailVerifier $emailVerifier,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'pendingCount' => $this->applications->countPending(),
            'userCount' => $this->users->count([]),
        ]);
    }

    // ── User management ──────────────────────────────────────────────────────

    #[Route('/users', name: 'users', methods: ['GET'])]
    public function users(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $page = max(1, $request->query->getInt('page', 1));
        $total = $this->users->countFiltered($q);

        return $this->render('admin/users.html.twig', [
            'users' => $this->users->findFilteredPaginated($q, $page, self::USERS_PER_PAGE),
            'q' => $q,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::USERS_PER_PAGE)),
            'total' => $total,
        ]);
    }

    /**
     * Bulk operations from the users list: re-send verification (with an
     * optional reason added to the e-mail), suspend/unsuspend, or delete.
     * Declared before /users/{user} so the literal path wins.
     */
    #[Route('/users/bulk', name: 'users_bulk', methods: ['POST'])]
    public function bulkUsers(Request $request, #[CurrentUser] User $admin): RedirectResponse
    {
        // Preserve the list's search/page context, but omit empty defaults so
        // the redirect stays a clean /admin/users when there is nothing to keep.
        $back = $this->redirectToRoute('app_admin_users', array_filter([
            'q' => trim((string) $request->request->get('q', '')),
            'page' => max(1, (int) $request->request->get('page', 1)),
        ], static fn ($v): bool => $v !== '' && $v !== 1));

        if (!$this->isCsrfTokenValid('admin_users_bulk', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'flash.invalid_token');

            return $back;
        }

        $users = $this->userAdmin->findUsers((array) $request->request->all('ids'));
        if ($users === []) {
            $this->addFlash('error', 'admin.users.bulk_none_selected');

            return $back;
        }

        $action = (string) $request->request->get('action');
        $reason = $action === UserAdminService::BULK_RESEND ? trim((string) $request->request->get('reason')) : '';
        $result = $this->userAdmin->bulk($action, $users, $admin, $reason);
        if ($result === null) {
            $this->addFlash('error', 'admin.users.bulk_unknown_action');

            return $back;
        }

        [$doneKey, $selfKey] = match ($action) {
            UserAdminService::BULK_RESEND => ['admin.users.bulk_resent', null],
            UserAdminService::BULK_SUSPEND => ['admin.users.bulk_suspended', 'admin.users.flash_no_self_suspend'],
            UserAdminService::BULK_UNSUSPEND => ['admin.users.bulk_unsuspended', null],
            UserAdminService::BULK_DELETE => ['admin.users.bulk_deleted', 'admin.users.flash_no_self_delete'],
        };
        $this->addFlash('success', $this->translator->trans($doneKey, ['%count%' => $result['count']]));
        if ($result['skippedSelf']) {
            $this->addFlash('error', $selfKey);
        }

        return $back;
    }

    #[Route('/users/{user}', name: 'user', methods: ['GET'])]
    public function user(User $user, #[CurrentUser] User $admin): Response
    {
        return $this->render('admin/user.html.twig', [
            'user' => $user,
            'assignableRoles' => UserAdminService::ASSIGNABLE_ROLES,
            'isSelf' => $this->userAdmin->isSelf($admin, $user),
        ]);
    }

    #[Route('/users/{user}/email', name: 'user_email', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"admin_user_email_" ~ args["user"].id'))]
    public function updateUserEmail(Request $request, User $user): RedirectResponse
    {
        [$type, $key] = match ($this->accounts->changeEmailByAdmin($user, trim((string) $request->request->get('email')))) {
            UserAccountService::EMAIL_INVALID => ['error', 'flash.invalid_email'],
            // Another account already uses this address.
            UserAccountService::EMAIL_TAKEN => ['error', 'admin.users.flash_email_taken'],
            default => ['success', 'admin.users.flash_email_updated'],
        };
        $this->addFlash($type, $key);

        return $this->redirectToRoute('app_admin_user', ['user' => $user->id]);
    }

    #[Route('/users/{user}/roles', name: 'user_roles', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"admin_user_roles_" ~ args["user"].id'))]
    public function updateUserRoles(Request $request, User $user, #[CurrentUser] User $admin): RedirectResponse
    {
        if ($this->userAdmin->updateRoles($user, (array) $request->request->all('roles'), $admin)) {
            $this->addFlash('success', 'admin.users.flash_roles_updated');
        } else {
            $this->addFlash('error', 'admin.users.flash_no_self_demote');
        }

        return $this->redirectToRoute('app_admin_user', ['user' => $user->id]);
    }

    #[Route('/users/{user}/suspend', name: 'user_suspend', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"admin_user_suspend_" ~ args["user"].id'))]
    public function toggleUserSuspension(Request $request, User $user, #[CurrentUser] User $admin): RedirectResponse
    {
        $suspend = $request->request->getBoolean('suspend');
        if ($this->userAdmin->setSuspended($user, $suspend, $admin)) {
            $this->addFlash('success', $suspend ? 'admin.users.flash_suspended' : 'admin.users.flash_unsuspended');
        } else {
            $this->addFlash('error', 'admin.users.flash_no_self_suspend');
        }

        return $this->redirectToRoute('app_admin_user', ['user' => $user->id]);
    }

    #[Route('/users/{user}/resend-verification', name: 'user_resend', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"admin_user_resend_" ~ args["user"].id'))]
    public function resendVerification(Request $request, User $user): RedirectResponse
    {
        $this->emailVerifier->sendVerification($user, trim((string) $request->request->get('reason')));
        $this->addFlash('success', 'admin.users.flash_verification_sent');

        return $this->redirectToRoute('app_admin_user', ['user' => $user->id]);
    }

    #[Route('/users/{user}/reset-link', name: 'user_reset_link', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"admin_user_reset_link_" ~ args["user"].id'))]
    public function sendResetLink(User $user): RedirectResponse
    {
        // Same one-time, hashed, one-hour token as the public forgot-password flow.
        $this->passwordReset->sendResetLink($user);
        $this->addFlash('success', 'admin.users.flash_reset_sent');

        return $this->redirectToRoute('app_admin_user', ['user' => $user->id]);
    }

    #[Route('/users/{user}/delete', name: 'user_delete', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"admin_user_delete_" ~ args["user"].id'))]
    public function deleteUser(User $user, #[CurrentUser] User $admin): RedirectResponse
    {
        if ($this->userAdmin->isSelf($admin, $user)) {
            $this->addFlash('error', 'admin.users.flash_no_self_delete');

            return $this->redirectToRoute('app_admin_user', ['user' => $user->id]);
        }

        $this->userDeletion->deleteUser($user->id);
        $this->addFlash('success', 'admin.users.flash_deleted');

        return $this->redirectToRoute('app_admin_users');
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
    #[IsCsrfTokenValid(new Expression('"admin_api_decision_" ~ args["application"].id'))]
    public function approve(ApiApplication $application, #[CurrentUser] User $admin): RedirectResponse
    {
        $this->applicationService->approve($application, $admin);
        $this->addFlash('success', 'admin.api.flash_approved');

        return $this->redirectToRoute('app_admin_api_application', ['application' => $application->id]);
    }

    #[Route('/api-applications/{application}/deny', name: 'api_deny', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"admin_api_decision_" ~ args["application"].id'))]
    public function deny(Request $request, ApiApplication $application, #[CurrentUser] User $admin): RedirectResponse
    {
        $reason = trim((string) $request->request->get('reason'));
        if ($reason === '') {
            $this->addFlash('error', 'admin.api.flash_reason_required');
        } else {
            $this->applicationService->deny($application, $admin, $reason);
            $this->addFlash('success', 'admin.api.flash_denied');
        }

        return $this->redirectToRoute('app_admin_api_application', ['application' => $application->id]);
    }

    #[Route('/api-applications/{application}/revoke', name: 'api_revoke', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"admin_api_decision_" ~ args["application"].id'))]
    public function revoke(Request $request, ApiApplication $application, #[CurrentUser] User $admin): RedirectResponse
    {
        $reason = trim((string) $request->request->get('reason'));
        if ($reason === '') {
            $this->addFlash('error', 'admin.api.flash_reason_required');
        } else {
            $this->applicationService->revoke($application, $admin, $reason);
            $this->addFlash('success', 'admin.api.flash_revoked');
        }

        return $this->redirectToRoute('app_admin_api_application', ['application' => $application->id]);
    }

    #[Route('/api-applications/{application}/message', name: 'api_message', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"admin_api_message_" ~ args["application"].id'))]
    public function message(Request $request, ApiApplication $application, #[CurrentUser] User $admin): RedirectResponse
    {
        $body = trim((string) $request->request->get('body'));
        if ($body === '') {
            $this->addFlash('error', 'admin.api.flash_message_empty');
        } else {
            $this->applicationService->postMessage($application, $admin, $body);
            $this->addFlash('success', 'admin.api.flash_message_sent');
        }

        return $this->redirectToRoute('app_admin_api_application', ['application' => $application->id]);
    }
}
