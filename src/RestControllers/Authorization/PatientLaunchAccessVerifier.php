<?php

declare(strict_types=1);

/**
 * Confirms an OAuth user may bind SMART patient context to a patient UUID (Track A / staging PHI baseline).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers\Authorization;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Services\UserService;

class PatientLaunchAccessVerifier
{
    /** @var callable(string): bool */
    private $patientUuidIsKnown;

    public function __construct(
        private readonly UserService $userService,
        ?callable $patientUuidIsKnown = null,
    ) {
        // Use a callable default (not a typed PatientService parameter) so isolated tests can load this class
        // without pulling in BaseService → code_types.inc.php (which requires the legacy SQL layer).
        $this->patientUuidIsKnown = $patientUuidIsKnown ?? static function (string $patientUuid): bool {
            $svc = new \OpenEMR\Services\PatientService();
            $result = $svc->getOne($patientUuid);

            return $result->isValid() && $result->hasData();
        };
    }

    /**
     * Whether the user identified by OAuth UUID may receive SMART patient context for the given patient UUID.
     */
    public function userMayBindSmartPatient(string $oauthUserUuid, string $patientUuid): bool
    {
        if ($oauthUserUuid === '' || $patientUuid === '') {
            return false;
        }

        $user = $this->userService->getUserByUUID($oauthUserUuid);
        if (empty($user) || empty($user['username'])) {
            return false;
        }

        if (AclMain::aclCheckCore('patients', 'demo', $user['username']) !== true) {
            return false;
        }

        return ($this->patientUuidIsKnown)($patientUuid);
    }
}
