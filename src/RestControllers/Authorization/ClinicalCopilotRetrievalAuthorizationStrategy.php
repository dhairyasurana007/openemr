<?php

/**
 * Authenticates clinical co-pilot retrieval routes when ``CLINICAL_COPILOT_INTERNAL_SECRET`` is set,
 * before the global Bearer strategy (which would otherwise reject requests without an OAuth token).
 *
 * Establishes an OpenEMR session user for ``RestConfig::request_authorization_check`` ACL checks.
 * Username comes from ``CLINICAL_COPILOT_SERVICE_USERNAME`` (default ``admin``).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\RestControllers\Authorization;

use OpenEMR\Common\Auth\UuidUserAccount;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\RestControllers\ClinicalCopilot\ClinicalCopilotInternalAuth;
use OpenEMR\Services\UserService;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class ClinicalCopilotRetrievalAuthorizationStrategy implements IAuthorizationStrategy
{
    private ?UserService $userService = null;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    private function getUserService(): UserService
    {
        return $this->userService ??= new UserService();
    }

    public function shouldProcessRequest(HttpRestRequest $request): bool
    {
        if (!ClinicalCopilotInternalAuth::isSecretConfigured()) {
            return false;
        }

        return $this->pathIsClinicalCopilotRetrieval($request);
    }

    private function pathIsClinicalCopilotRetrieval(HttpRestRequest $request): bool
    {
        $pathInfo = (string) $request->getPathInfo();
        $sitePath = '/' . $request->getRequestSite();
        if (str_starts_with($pathInfo, $sitePath)) {
            $pathInfo = substr($pathInfo, strlen($sitePath));
        }

        return str_starts_with($pathInfo, '/api/clinical-copilot/retrieval');
    }

    public function authorizeRequest(HttpRestRequest $request): bool
    {
        ClinicalCopilotInternalAuth::assertConfiguredSecretMatches($request);

        $username = trim((string) (getenv('CLINICAL_COPILOT_SERVICE_USERNAME') ?: 'admin'));
        if ($username === '') {
            $username = 'admin';
        }

        $user = $this->getUserService()->getUserByUsername($username);
        if (!is_array($user) || $user === []) {
            $this->logger->error(
                'ClinicalCopilotRetrievalAuthorizationStrategy: service user not found',
                ['username' => $username]
            );
            throw new ServiceUnavailableHttpException(
                null,
                'Clinical co-pilot service user is not configured correctly'
            );
        }

        $userUuid = (string) ($user['uuid'] ?? '');
        if ($userUuid === '') {
            $this->logger->error(
                'ClinicalCopilotRetrievalAuthorizationStrategy: service user missing uuid',
                ['username' => $username]
            );
            throw new ServiceUnavailableHttpException(
                null,
                'Clinical co-pilot service user has no UUID'
            );
        }

        $request->attributes->set('skipAuthorization', true);
        $session = $request->getSession();
        $session->set('userId', $userUuid);
        $session->set('userRole', UuidUserAccount::USER_ROLE_USERS);
        $session->set('authUser', $user['username'] ?? null);
        $session->set('authUserID', $user['id'] ?? null);
        $authProvider = $this->getUserService()->getAuthGroupForUser((string) ($user['username'] ?? ''));
        $session->set('authProvider', $authProvider);

        if (
            empty($session->get('authUser')) || empty($session->get('authUserID'))
            || empty($session->get('authProvider'))
        ) {
            $this->logger->error('ClinicalCopilotRetrievalAuthorizationStrategy: incomplete session bootstrap');
            throw new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'Clinical co-pilot session bootstrap failed');
        }

        $request->setRequestUser($userUuid, $user);
        $request->setRequestUserRole(UuidUserAccount::USER_ROLE_USERS);

        return true;
    }
}
