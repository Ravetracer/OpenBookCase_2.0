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
use App\Service\UserDeletionService;

use Doctrine\ORM\EntityManagerInterface;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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
    private const USERS_PER_PAGE = 50;
    private const TOKEN_TTL = '+1 hour';

    /**
     * Roles an admin may assign/revoke from the user-management UI. ROLE_USER is
     * implicit (granted to everyone in User::getRoles) and is never stored, so it
     * is not listed here. Add new roles to this list as they are introduced.
     *
     * @var list<string>
     */
    public const ASSIGNABLE_ROLES = ['ROLE_ADMIN'];

    public function __construct(
        private readonly ApiApplicationRepository $applications,
        private readonly MessageRepository $messages,
        private readonly ApiApplicationService $applicationService,
        private readonly ApiUsageLogRepository $usageLogs,
        private readonly UserRepository $users,
        private readonly UserDeletionService $userDeletion,
        private readonly EntityManagerInterface $entityManager,
        private readonly EmailVerifier $emailVerifier,
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly ValidatorInterface $validator,
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
    public function bulkUsers(Request $request): RedirectResponse
    {
        // Preserve the list's search/page context, but omit empty defaults so
        // the redirect stays a clean /admin/users when there is nothing to keep.
        $params = [];
        if (($q = trim((string) $request->request->get('q', ''))) !== '') {
            $params['q'] = $q;
        }
        if (($page = max(1, (int) $request->request->get('page', 1))) > 1) {
            $params['page'] = $page;
        }
        $back = fn (): RedirectResponse => $this->redirectToRoute('app_admin_users', $params);

        if (!$this->isCsrfTokenValid('admin_users_bulk', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'flash.invalid_token');

            return $back();
        }

        $ids = array_values(array_filter(
            (array) $request->request->all('ids'),
            static fn ($id): bool => \is_string($id) && Ulid::isValid($id),
        ));

        $users = [];
        foreach ($ids as $id) {
            $found = $this->users->find(Ulid::fromString($id));
            if ($found instanceof User) {
                $users[] = $found;
            }
        }

        if ($users === []) {
            $this->addFlash('error', 'admin.users.bulk_none_selected');

            return $back();
        }

        switch ((string) $request->request->get('action')) {
            case 'resend':
                $reason = trim((string) $request->request->get('reason'));
                foreach ($users as $u) {
                    $this->sendVerification($u, $reason);
                }
                $this->addFlash('success', $this->translator->trans('admin.users.bulk_resent', ['%count%' => \count($users)]));
                break;

            case 'suspend':
            case 'unsuspend':
                $suspend = $request->request->get('action') === 'suspend';
                $affected = 0;
                $skippedSelf = false;
                foreach ($users as $u) {
                    // An admin must not lock themselves out of the admin area.
                    if ($suspend && $this->isSelf($u)) {
                        $skippedSelf = true;
                        continue;
                    }
                    $u->isSuspended = $suspend;
                    ++$affected;
                }
                $this->entityManager->flush();
                $this->addFlash('success', $this->translator->trans($suspend ? 'admin.users.bulk_suspended' : 'admin.users.bulk_unsuspended', ['%count%' => $affected]));
                if ($skippedSelf) {
                    $this->addFlash('error', 'admin.users.flash_no_self_suspend');
                }
                break;

            case 'delete':
                $deleted = 0;
                $skippedSelf = false;
                foreach ($users as $u) {
                    if ($this->isSelf($u)) {
                        $skippedSelf = true;
                        continue;
                    }
                    $this->userDeletion->deleteUser($u->id);
                    ++$deleted;
                }
                $this->addFlash('success', $this->translator->trans('admin.users.bulk_deleted', ['%count%' => $deleted]));
                if ($skippedSelf) {
                    $this->addFlash('error', 'admin.users.flash_no_self_delete');
                }
                break;

            default:
                $this->addFlash('error', 'admin.users.bulk_unknown_action');
        }

        return $back();
    }

    #[Route('/users/{user}', name: 'user', methods: ['GET'])]
    public function user(User $user): Response
    {
        return $this->render('admin/user.html.twig', [
            'user' => $user,
            'assignableRoles' => self::ASSIGNABLE_ROLES,
            'isSelf' => $this->isSelf($user),
        ]);
    }

    #[Route('/users/{user}/email', name: 'user_email', methods: ['POST'])]
    public function updateUserEmail(Request $request, User $user): RedirectResponse
    {
        if ($this->validUserToken($request, 'admin_user_email', $user)) {
            $email = trim((string) $request->request->get('email'));
            $violations = $this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()]);
            $existing = $email !== '' ? $this->users->loadUserByIdentifier($email) : null;

            if (count($violations) > 0) {
                $this->addFlash('error', 'flash.invalid_email');
            } elseif ($existing instanceof User && (string) $existing->id !== (string) $user->id) {
                // Another account already uses this address.
                $this->addFlash('error', 'admin.users.flash_email_taken');
            } else {
                $user->email = $email;
                // A corrected address has not been proven yet — require re-verification.
                $user->isVerified = false;
                $this->entityManager->flush();
                $this->addFlash('success', 'admin.users.flash_email_updated');
            }
        }

        return $this->redirectToRoute('app_admin_user', ['user' => $user->id]);
    }

    #[Route('/users/{user}/roles', name: 'user_roles', methods: ['POST'])]
    public function updateUserRoles(Request $request, User $user): RedirectResponse
    {
        if ($this->validUserToken($request, 'admin_user_roles', $user)) {
            /** @var string[] $submitted */
            $submitted = (array) $request->request->all('roles');
            $roles = array_values(array_intersect(self::ASSIGNABLE_ROLES, $submitted));

            // Guard against an admin removing their own ROLE_ADMIN and locking
            // themselves out of the admin area.
            if ($this->isSelf($user) && !in_array('ROLE_ADMIN', $roles, true)) {
                $this->addFlash('error', 'admin.users.flash_no_self_demote');
            } else {
                $user->roles = $roles;
                $this->entityManager->flush();
                $this->addFlash('success', 'admin.users.flash_roles_updated');
            }
        }

        return $this->redirectToRoute('app_admin_user', ['user' => $user->id]);
    }

    #[Route('/users/{user}/suspend', name: 'user_suspend', methods: ['POST'])]
    public function toggleUserSuspension(Request $request, User $user): RedirectResponse
    {
        if ($this->validUserToken($request, 'admin_user_suspend', $user)) {
            $suspend = $request->request->getBoolean('suspend');
            if ($suspend && $this->isSelf($user)) {
                $this->addFlash('error', 'admin.users.flash_no_self_suspend');
            } else {
                $user->isSuspended = $suspend;
                $this->entityManager->flush();
                $this->addFlash('success', $suspend ? 'admin.users.flash_suspended' : 'admin.users.flash_unsuspended');
            }
        }

        return $this->redirectToRoute('app_admin_user', ['user' => $user->id]);
    }

    #[Route('/users/{user}/resend-verification', name: 'user_resend', methods: ['POST'])]
    public function resendVerification(Request $request, User $user): RedirectResponse
    {
        if ($this->validUserToken($request, 'admin_user_resend', $user)) {
            $this->sendVerification($user, trim((string) $request->request->get('reason')));
            $this->addFlash('success', 'admin.users.flash_verification_sent');
        }

        return $this->redirectToRoute('app_admin_user', ['user' => $user->id]);
    }

    #[Route('/users/{user}/reset-link', name: 'user_reset_link', methods: ['POST'])]
    public function sendResetLink(Request $request, User $user): RedirectResponse
    {
        if ($this->validUserToken($request, 'admin_user_reset_link', $user)) {
            // Same one-time, hashed, one-hour token as the public forgot-password flow.
            $token = bin2hex(random_bytes(32));
            $user->resetTokenHash = hash('sha256', $token);
            $user->resetTokenExpiresAt = new \DateTimeImmutable(self::TOKEN_TTL);
            $this->entityManager->flush();

            $resetUrl = $this->generateUrl(
                'app_reset_password',
                ['token' => $token],
                UrlGeneratorInterface::ABSOLUTE_URL,
            );

            $this->mailer->send((new TemplatedEmail())
                ->from(new Address('info@openbookcase.de', 'OpenBookCase'))
                ->to($user->email)
                ->subject($this->translator->trans('reset.email_subject'))
                ->htmlTemplate('security/reset_email.html.twig')
                ->context(['resetUrl' => $resetUrl, 'username' => $user->getUserIdentifier()]));

            $this->addFlash('success', 'admin.users.flash_reset_sent');
        }

        return $this->redirectToRoute('app_admin_user', ['user' => $user->id]);
    }

    #[Route('/users/{user}/delete', name: 'user_delete', methods: ['POST'])]
    public function deleteUser(Request $request, User $user): RedirectResponse
    {
        if ($this->validUserToken($request, 'admin_user_delete', $user)) {
            if ($this->isSelf($user)) {
                $this->addFlash('error', 'admin.users.flash_no_self_delete');

                return $this->redirectToRoute('app_admin_user', ['user' => $user->id]);
            }

            $this->userDeletion->deleteUser($user->id);
            $this->addFlash('success', 'admin.users.flash_deleted');

            return $this->redirectToRoute('app_admin_users');
        }

        return $this->redirectToRoute('app_admin_user', ['user' => $user->id]);
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

    /**
     * Send the e-mail verification link to a user, optionally with an admin note
     * (e.g. explaining why they are receiving a fresh link) rendered in the mail.
     */
    private function sendVerification(User $user, string $reason = ''): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address('info@openbookcase.de', 'OpenBookCase'))
            ->to($user->email)
            ->subject($this->translator->trans('email.confirm_subject'))
            ->htmlTemplate('registration/confirmation_email.html.twig');

        if ($reason !== '') {
            $email->context(['adminReason' => $reason]);
        }

        $this->emailVerifier->sendEmailConfirmation('app_verify_email', $user, $email);
    }

    private function validToken(Request $request, string $id, ApiApplication $application): bool
    {
        $valid = $this->isCsrfTokenValid($id . '_' . $application->id, (string) $request->request->get('_token'));
        if (!$valid) {
            $this->addFlash('error', 'flash.invalid_token');
        }

        return $valid;
    }

    private function validUserToken(Request $request, string $id, User $user): bool
    {
        $valid = $this->isCsrfTokenValid($id . '_' . $user->id, (string) $request->request->get('_token'));
        if (!$valid) {
            $this->addFlash('error', 'flash.invalid_token');
        }

        return $valid;
    }

    /** Is the given user the currently logged-in admin? (Self-action guard.) */
    private function isSelf(User $user): bool
    {
        return $user->id !== null && (string) $this->admin()->id === (string) $user->id;
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
