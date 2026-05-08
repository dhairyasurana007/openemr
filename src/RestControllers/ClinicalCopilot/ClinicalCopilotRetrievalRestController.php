<?php

/**
 * Read-only retrieval bridge for Clinical Co-Pilot (stable JSON + citations).
 *
 * Schema-bound retrieval tools: schedule slots, calendar window, patient core profile,
 * medications, observations (vitals + laboratory), encounters with notes, and
 * referrals/orders/care gaps. Optional narrow routes: vitals-only and laboratory-results-only.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\RestControllers\ClinicalCopilot;

use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Auth\OpenIDConnect\Repositories\ClientRepository;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\RestControllers\Config\RestConfig;
use OpenEMR\Services\AllergyIntoleranceService;
use OpenEMR\Services\AppointmentService;
use OpenEMR\Services\ConditionService;
use OpenEMR\Services\EncounterService;
use OpenEMR\Services\PatientService;
use OpenEMR\Services\PrescriptionService;
use OpenEMR\Services\ProcedureService;
use OpenEMR\Services\VitalsService;
use OpenEMR\Services\Search\DateSearchField;
use OpenEMR\Services\Search\SearchModifier;
use OpenEMR\Services\Search\StringSearchField;
use OpenEMR\Validators\ProcessingResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ClinicalCopilotRetrievalRestController
{
    public const SCHEMA_VERSION = '1.0.0';

    public function __construct(
        private readonly AppointmentService $appointmentService = new AppointmentService(),
        private readonly PatientService $patientService = new PatientService(),
        private readonly ConditionService $conditionService = new ConditionService(),
        private readonly AllergyIntoleranceService $allergyIntoleranceService = new AllergyIntoleranceService(),
        private readonly PrescriptionService $prescriptionService = new PrescriptionService(),
        private readonly ProcedureService $procedureService = new ProcedureService(),
        private readonly EncounterService $encounterService = new EncounterService(),
        private readonly VitalsService $vitalsService = new VitalsService(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public static function citation(string $tool, string $domain, string $path, string $method = 'GET'): array
    {
        return [
            'type' => 'openemr_rest',
            'tool' => $tool,
            'domain' => $domain,
            'method' => $method,
            'path' => $path,
        ];
    }

    public function listScheduleSlots(HttpRestRequest $request): JsonResponse
    {
        ClinicalCopilotInternalAuth::assertConfiguredSecretMatches($request);
        RestConfig::request_authorization_check($request, 'patients', 'appt');

        $date = (string) $request->query->get('date', '');
        if ($date === '') {
            throw new BadRequestHttpException('Missing required query parameter: date');
        }

        $search = [
            'pc_eventDate' => new DateSearchField('pc_eventDate', ['eq' . $date], DateSearchField::DATE_TYPE_DATE),
        ];
        $facilityId = $request->query->get('facility_id');
        if ($facilityId !== null && $facilityId !== '') {
            $search['pc_facility'] = new StringSearchField('pc_facility', [(string) $facilityId], SearchModifier::EXACT);
        }

        $processingResult = $this->appointmentService->search($search, true);
        if ($processingResult->hasErrors()) {
            return $this->processingResultJson($processingResult);
        }

        $slots = [];
        foreach ($processingResult->getData() as $row) {
            $slots[] = $this->normalizeScheduleSlot($row);
        }

        $slots = $this->filterSlotsByWindow($slots, (string) $request->query->get('time_start', ''), (string) $request->query->get('time_end', ''), (string) $request->query->get('window', ''));

        $body = [
            'tool' => 'list_schedule_slots',
            'schema_version' => self::SCHEMA_VERSION,
            'citations' => [
                self::citation('list_schedule_slots', 'schedule', '/api/clinical-copilot/retrieval/list-schedule-slots'),
            ],
            'date' => $date,
            'slots' => $slots,
        ];

        return new JsonResponse($body, Response::HTTP_OK);
    }

    public function getCalendar(HttpRestRequest $request): JsonResponse
    {
        ClinicalCopilotInternalAuth::assertConfiguredSecretMatches($request);
        RestConfig::request_authorization_check($request, 'patients', 'appt');

        $startDate = (string) $request->query->get('start_date', '');
        $endDate = (string) $request->query->get('end_date', '');
        if ($startDate === '') {
            throw new BadRequestHttpException('Missing required query parameter: start_date');
        }

        if ($endDate === '') {
            $endDate = $startDate;
        }

        $this->assertIsoYmd('start_date', $startDate);
        $this->assertIsoYmd('end_date', $endDate);

        $start = new \DateTimeImmutable($startDate);
        $end = new \DateTimeImmutable($endDate);
        if ($end < $start) {
            throw new BadRequestHttpException('end_date must be on or after start_date');
        }

        if ($start->diff($end)->days > 120) {
            throw new BadRequestHttpException('Date window exceeds maximum of 120 days');
        }

        $search = [
            'pc_eventDate' => new DateSearchField(
                'pc_eventDate',
                ['ge' . $startDate, 'le' . $endDate],
                DateSearchField::DATE_TYPE_DATE,
                true
            ),
        ];

        $facilityId = $request->query->get('facility_id');
        if ($facilityId !== null && $facilityId !== '') {
            $search['pc_facility'] = new StringSearchField('pc_facility', [(string) $facilityId], SearchModifier::EXACT);
        }

        $calendarId = (string) $request->query->get('calendar_id', '');
        if ($calendarId !== '') {
            $search['pc_catid'] = new StringSearchField('pc_catid', [$calendarId], SearchModifier::EXACT);
        }

        $processingResult = $this->appointmentService->search($search, true);
        if ($processingResult->hasErrors()) {
            return $this->processingResultJson($processingResult);
        }

        $events = [];
        foreach ($processingResult->getData() as $row) {
            $events[] = $this->normalizeCalendarEvent($row);
        }

        $categorySearch = [];
        if ($calendarId !== '') {
            $categorySearch['pc_catid'] = new StringSearchField('pc_catid', [$calendarId], SearchModifier::EXACT);
        }

        $calendars = [];
        $categoryResult = $this->appointmentService->searchCalendarCategories($categorySearch);
        if (!$categoryResult->hasErrors()) {
            foreach ($categoryResult->getData() as $catRow) {
                $calendars[] = $this->normalizeCalendarCategory($catRow);
            }
        }

        $body = [
            'tool' => 'get_calendar',
            'schema_version' => self::SCHEMA_VERSION,
            'citations' => [
                self::citation('get_calendar', 'calendar', '/api/clinical-copilot/retrieval/calendar'),
            ],
            'query' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'calendar_id' => $calendarId,
                'facility_id' => $facilityId !== null && $facilityId !== '' ? (string) $facilityId : '',
            ],
            'calendars' => $calendars,
            'events' => $events,
        ];

        return new JsonResponse($body, Response::HTTP_OK);
    }

    public function getPatientCoreProfile(HttpRestRequest $request): JsonResponse
    {
        ClinicalCopilotInternalAuth::assertConfiguredSecretMatches($request);
        RestConfig::request_authorization_check($request, 'patients', 'demo');
        RestConfig::request_authorization_check($request, 'patients', 'med');

        $puuid = (string) $request->query->get('patient', '');
        if ($puuid === '') {
            throw new BadRequestHttpException('Missing required query parameter: patient (patient UUID)');
        }

        $demographics = $this->patientService->getOne($puuid);
        if ($demographics->hasErrors()) {
            return $this->processingResultJson($demographics);
        }
        $demoRows = $demographics->getData();
        if ($demoRows === []) {
            return new JsonResponse([
                'tool' => 'get_patient_core_profile',
                'schema_version' => self::SCHEMA_VERSION,
                'error' => 'patient_not_found',
                'citations' => [
                    self::citation('get_patient_core_profile', 'demographics', '/api/clinical-copilot/retrieval/patient-core-profile'),
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        $problems = $this->conditionService->getAll(['puuid' => $puuid], true, $puuid);
        if ($problems->hasErrors()) {
            return $this->processingResultJson($problems);
        }

        $allergies = $this->allergyIntoleranceService->getAll(['puuid' => $puuid], true, $puuid);
        if ($allergies->hasErrors()) {
            return $this->processingResultJson($allergies);
        }

        $body = [
            'tool' => 'get_patient_core_profile',
            'schema_version' => self::SCHEMA_VERSION,
            'citations' => [
                self::citation('get_patient_core_profile', 'demographics', '/api/patient/{puuid}', 'GET'),
                self::citation('get_patient_core_profile', 'problems', '/api/patient/{puuid}/medical_problem', 'GET'),
                self::citation('get_patient_core_profile', 'allergies', '/api/patient/{puuid}/allergy', 'GET'),
            ],
            'demographics' => $this->normalizeDemographics($demoRows[0]),
            'active_problems' => array_map(fn (array $r): array => $this->normalizeProblem($r), $problems->getData()),
            'allergies' => array_map(fn (array $r): array => $this->normalizeAllergy($r), $allergies->getData()),
        ];

        return new JsonResponse($body, Response::HTTP_OK);
    }

    public function findPatientCandidates(HttpRestRequest $request): JsonResponse
    {
        ClinicalCopilotInternalAuth::assertConfiguredSecretMatches($request);
        RestConfig::request_authorization_check($request, 'patients', 'demo');

        $name = trim((string) $request->query->get('name', ''));
        if (mb_strlen($name) < 2) {
            throw new BadRequestHttpException('Missing or too-short query parameter: name (min 2 characters)');
        }

        $limitRaw = (string) $request->query->get('limit', '5');
        $limit = (int) $limitRaw;
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 10) {
            $limit = 10;
        }

        $search = [
            'fname' => $name,
            'lname' => $name,
            'mname' => $name,
            'pubpid' => $name,
            'pid' => $name,
        ];
        $processingResult = $this->patientService->getAll($search, false);
        if ($processingResult->hasErrors()) {
            return $this->processingResultJson($processingResult);
        }

        $candidates = [];
        foreach (array_slice($processingResult->getData(), 0, $limit) as $row) {
            $candidates[] = $this->normalizePatientCandidate($row);
        }

        $body = [
            'tool' => 'find_patient_candidates',
            'schema_version' => self::SCHEMA_VERSION,
            'citations' => [
                self::citation('find_patient_candidates', 'demographics', '/api/clinical-copilot/retrieval/find-patient-candidates'),
            ],
            'query' => $name,
            'candidates' => $candidates,
        ];

        return new JsonResponse($body, Response::HTTP_OK);
    }

    public function getMedicationList(HttpRestRequest $request): JsonResponse
    {
        ClinicalCopilotInternalAuth::assertConfiguredSecretMatches($request);
        RestConfig::request_authorization_check($request, 'patients', 'med');

        $puuid = (string) $request->query->get('patient', '');
        if ($puuid === '') {
            throw new BadRequestHttpException('Missing required query parameter: patient (patient UUID)');
        }

        $processingResult = $this->prescriptionService->getAll(['patient.uuid' => $puuid], true);
        if ($processingResult->hasErrors()) {
            return $this->processingResultJson($processingResult);
        }

        $medications = [];
        foreach ($processingResult->getData() as $row) {
            $medications[] = $this->normalizeMedication($row);
        }

        $body = [
            'tool' => 'get_medication_list',
            'schema_version' => self::SCHEMA_VERSION,
            'citations' => [
                self::citation('get_medication_list', 'medications', '/api/prescription', 'GET'),
            ],
            'medications' => $medications,
        ];

        return new JsonResponse($body, Response::HTTP_OK);
    }

    public function getObservations(HttpRestRequest $request): JsonResponse
    {
        return $this->buildObservationsResponse($request, 'get_observations', 'both');
    }

    public function getVitals(HttpRestRequest $request): JsonResponse
    {
        return $this->buildObservationsResponse($request, 'get_vitals', 'vitals');
    }

    public function getLaboratoryResults(HttpRestRequest $request): JsonResponse
    {
        return $this->buildObservationsResponse($request, 'get_laboratory_results', 'laboratory');
    }

    public function getEncountersAndNotes(HttpRestRequest $request): JsonResponse
    {
        ClinicalCopilotInternalAuth::assertConfiguredSecretMatches($request);
        RestConfig::request_authorization_check($request, 'encounters', 'auth_a');

        $puuid = (string) $request->query->get('patient', '');
        if ($puuid === '') {
            throw new BadRequestHttpException('Missing required query parameter: patient (patient UUID)');
        }

        $encounterResult = $this->encounterService->search([], true, $puuid);
        if ($encounterResult->hasErrors()) {
            return $this->processingResultJson($encounterResult);
        }

        $encounters = [];
        foreach ($encounterResult->getData() as $enc) {
            $pid = (string) ($enc['pid'] ?? '');
            $eid = (string) ($enc['eid'] ?? '');
            $notes = [];
            if ($pid !== '' && $eid !== '') {
                $notes = $this->encounterService->getSoapNotes($pid, $eid);
            }

            $encounters[] = [
                'encounter_uuid' => (string) ($enc['uuid'] ?? ''),
                'encounter_id' => $eid,
                'date' => (string) ($enc['date'] ?? ''),
                'reason' => (string) ($enc['reason'] ?? ''),
                'facility' => (string) ($enc['facility'] ?? ''),
                'soap_notes' => array_map(fn (array $n): array => $this->normalizeSoapNote($n), $notes),
            ];
        }

        $body = [
            'tool' => 'get_encounters_and_notes',
            'schema_version' => self::SCHEMA_VERSION,
            'citations' => [
                self::citation('get_encounters_and_notes', 'encounters', '/api/patient/{puuid}/encounter', 'GET'),
                self::citation('get_encounters_and_notes', 'documentation', '/api/patient/{pid}/encounter/{eid}/soap_note', 'GET'),
            ],
            'encounters' => $encounters,
        ];

        return new JsonResponse($body, Response::HTTP_OK);
    }

    public function getReferralsOrdersCareGaps(HttpRestRequest $request): JsonResponse
    {
        ClinicalCopilotInternalAuth::assertConfiguredSecretMatches($request);
        RestConfig::request_authorization_check($request, 'patients', 'med');

        $puuid = (string) $request->query->get('patient', '');
        if ($puuid === '') {
            throw new BadRequestHttpException('Missing required query parameter: patient (patient UUID)');
        }

        $ordersResult = $this->procedureService->getAll(['patient.uuid' => $puuid], true, $puuid);
        if ($ordersResult->hasErrors()) {
            return $this->processingResultJson($ordersResult);
        }

        $orders = [];
        foreach ($ordersResult->getData() as $order) {
            $orders[] = $this->normalizeProcedureOrder($order);
        }

        $body = [
            'tool' => 'get_referrals_orders_care_gaps',
            'schema_version' => self::SCHEMA_VERSION,
            'citations' => [
                self::citation('get_referrals_orders_care_gaps', 'orders', '/api/procedure', 'GET'),
            ],
            'referrals' => [],
            'orders' => $orders,
            'care_gaps' => [],
        ];

        return new JsonResponse($body, Response::HTTP_OK);
    }

    private function buildObservationsResponse(HttpRestRequest $request, string $tool, string $mode): JsonResponse
    {
        ClinicalCopilotInternalAuth::assertConfiguredSecretMatches($request);
        RestConfig::request_authorization_check($request, 'patients', 'med');

        $puuid = (string) $request->query->get('patient', '');
        if ($puuid === '') {
            throw new BadRequestHttpException('Missing required query parameter: patient (patient UUID)');
        }

        $pid = $this->resolvePidFromPatientUuid($puuid);
        if ($pid === null) {
            return new JsonResponse([
                'tool' => $tool,
                'schema_version' => self::SCHEMA_VERSION,
                'error' => 'patient_not_found',
                'citations' => [
                    self::citation($tool, 'patient', '/api/patient/{puuid}', 'GET'),
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        $vitalsSearch = [
            new StringSearchField('pid', (string) $pid, SearchModifier::EXACT),
            new StringSearchField('deleted', '0', SearchModifier::EXACT),
            new StringSearchField('formdir', 'vitals', SearchModifier::EXACT),
        ];
        $vitalsResult = $this->vitalsService->search($vitalsSearch);
        if ($vitalsResult->hasErrors()) {
            return $this->processingResultJson($vitalsResult);
        }

        $vitalsPayload = [];
        foreach ($vitalsResult->getData() as $v) {
            $vitalsPayload[] = $this->normalizeVitalsPanel($v);
        }

        $labResult = $this->procedureService->getAll(['patient.uuid' => $puuid], true, $puuid);
        if ($labResult->hasErrors()) {
            return $this->processingResultJson($labResult);
        }
        $laboratory = [];
        foreach ($labResult->getData() as $order) {
            $laboratory[] = $this->normalizeLaboratorySummary($order);
        }

        $body = [
            'tool' => $tool,
            'schema_version' => self::SCHEMA_VERSION,
            'citations' => [
                self::citation($tool, 'vitals', '/api/patient/{pid}/encounter/{eid}/vital', 'GET'),
                self::citation($tool, 'laboratory', '/api/procedure', 'GET'),
            ],
            'vitals' => $mode === 'laboratory' ? [] : $vitalsPayload,
            'laboratory' => $mode === 'vitals' ? [] : $laboratory,
        ];

        return new JsonResponse($body, Response::HTTP_OK);
    }

    private function processingResultJson(ProcessingResult $processingResult): JsonResponse
    {
        $status = $processingResult->hasErrors() ? Response::HTTP_INTERNAL_SERVER_ERROR : Response::HTTP_BAD_REQUEST;
        return new JsonResponse([
            'validationErrors' => $processingResult->getValidationMessages(),
            'internalErrors' => $processingResult->getInternalErrors(),
        ], $status);
    }

    private function resolvePidFromPatientUuid(string $puuid): ?string
    {
        $pr = $this->patientService->getOne($puuid);
        if ($pr->hasErrors() || $pr->getData() === []) {
            return null;
        }
        $row = $pr->getData()[0];

        return isset($row['pid']) ? (string) $row['pid'] : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function assertIsoYmd(string $name, string $value): void
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($dt === false || $dt->format('Y-m-d') !== $value) {
            throw new BadRequestHttpException('Invalid query parameter ' . $name . ' (expected YYYY-MM-DD)');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeCalendarCategory(array $row): array
    {
        return [
            'calendar_id' => (string) ($row['pc_catid'] ?? ''),
            'name' => (string) ($row['pc_catname'] ?? ''),
            'color' => (string) ($row['pc_catcolor'] ?? ''),
            'type' => (string) ($row['pc_cattype'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeCalendarEvent(array $row): array
    {
        $base = $this->normalizeScheduleSlot($row);

        return array_merge($base, [
            'calendar_category_id' => (string) ($row['pc_catid'] ?? ''),
        ]);
    }

    private function normalizeScheduleSlot(array $row): array
    {
        return [
            'slot_id' => (string) ($row['pc_eid'] ?? ''),
            'slot_uuid' => (string) ($row['pc_uuid'] ?? ''),
            'patient_uuid' => (string) ($row['puuid'] ?? ''),
            'patient_display' => trim((string) ($row['fname'] ?? '') . ' ' . (string) ($row['lname'] ?? '')),
            'start_date' => (string) ($row['pc_eventDate'] ?? ''),
            'start_time' => (string) ($row['pc_startTime'] ?? ''),
            'end_time' => (string) ($row['pc_endTime'] ?? ''),
            'visit_type' => (string) ($row['pc_title'] ?? ''),
            'status_code' => (string) ($row['pc_apptstatus'] ?? ''),
            'facility_id' => (string) ($row['pc_facility'] ?? ''),
            'facility_uuid' => (string) ($row['facility_uuid'] ?? ''),
        ];
    }

    /**
     * @param list<array<string, mixed>> $slots
     * @return list<array<string, mixed>>
     */
    private function filterSlotsByWindow(array $slots, string $timeStart, string $timeEnd, string $window): array
    {
        if ($window === 'remainder_after' || $window === 'remainder_after_noon') {
            $timeStart = '12:00:00';
        }

        if ($timeStart === '' && $timeEnd === '') {
            return $slots;
        }

        $filtered = [];
        foreach ($slots as $slot) {
            $t = (string) ($slot['start_time'] ?? '');
            if ($timeStart !== '' && strcmp($t, $timeStart) < 0) {
                continue;
            }
            if ($timeEnd !== '' && strcmp($t, $timeEnd) > 0) {
                continue;
            }
            $filtered[] = $slot;
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeDemographics(array $row): array
    {
        return [
            'patient_uuid' => (string) ($row['uuid'] ?? ''),
            'pid' => (string) ($row['pid'] ?? ''),
            'first_name' => (string) ($row['fname'] ?? ''),
            'last_name' => (string) ($row['lname'] ?? ''),
            'DOB' => (string) ($row['DOB'] ?? ''),
            'sex' => (string) ($row['sex'] ?? ''),
        ];
    }

    public function bootstrapOauthClient(HttpRestRequest $request): JsonResponse
    {
        ClinicalCopilotInternalAuth::assertConfiguredSecretMatches($request);

        $raw = $request->getContent();
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            throw new BadRequestHttpException('Invalid JSON payload');
        }

        $clientId = trim((string) ($payload['client_id'] ?? ''));
        $clientSecret = trim((string) ($payload['client_secret'] ?? ''));
        $scope = trim((string) ($payload['scope'] ?? 'api:fhir'));
        if ($clientId === '' || $clientSecret === '') {
            throw new BadRequestHttpException('Missing required fields: client_id and client_secret');
        }

        $siteId = (string) ($GLOBALS['oe_site_id'] ?? 'default');
        $encryptedSecret = ServiceContainer::getCrypto()->encryptStandard($clientSecret);
        $existing = QueryUtils::querySingleRow('SELECT client_id FROM oauth_clients WHERE client_id = ?', [$clientId]);

        if (is_array($existing)) {
            QueryUtils::sqlStatementThrowException(
                'UPDATE oauth_clients SET client_secret = ?, grant_types = ?, scope = ?, is_confidential = 1, is_enabled = 1 WHERE client_id = ?',
                [$encryptedSecret, 'client_credentials', $scope, $clientId]
            );
            $status = 'updated';
        } else {
            $repo = new ClientRepository();
            $ok = $repo->insertNewClient($clientId, [
                'client_role' => 'service',
                'client_name' => 'Clinical Copilot Agent',
                'client_secret' => $clientSecret,
                'registration_access_token' => '',
                'registration_client_uri_path' => '',
                'contacts' => '',
                'redirect_uris' => '',
                'grant_types' => 'client_credentials',
                'scope' => $scope,
                'skip_ehr_launch_authorization_flow' => true,
                'dsi_type' => 0,
            ], $siteId);
            if (!$ok) {
                return new JsonResponse(['error' => 'Failed to create OAuth client'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
            $status = 'created';
        }

        return new JsonResponse([
            'ok' => true,
            'status' => $status,
            'client_id' => $clientId,
            'scope' => $scope,
            'site_id' => $siteId,
        ], Response::HTTP_OK);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    private function normalizePatientCandidate(array $row): array
    {
        $first = trim((string) ($row['fname'] ?? ''));
        $middle = trim((string) ($row['mname'] ?? ''));
        $last = trim((string) ($row['lname'] ?? ''));
        $nameParts = array_values(array_filter([$first, $middle, $last], static fn ($value): bool => $value !== ''));

        return [
            'patient_uuid' => (string) ($row['uuid'] ?? ''),
            'pid' => (string) ($row['pid'] ?? ''),
            'display_name' => trim(implode(' ', $nameParts)),
            'dob' => (string) ($row['DOB'] ?? ''),
            'sex' => (string) ($row['sex'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeProblem(array $row): array
    {
        return [
            'condition_uuid' => (string) ($row['uuid'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'begdate' => (string) ($row['begdate'] ?? ''),
            'enddate' => (string) ($row['enddate'] ?? ''),
            'diagnosis' => (string) ($row['diagnosis'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeAllergy(array $row): array
    {
        return [
            'allergy_uuid' => (string) ($row['uuid'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'begdate' => (string) ($row['begdate'] ?? ''),
            'reaction' => (string) ($row['reaction_title'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeMedication(array $row): array
    {
        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'drug' => (string) ($row['drug'] ?? ''),
            'dosage' => (string) ($row['dosage'] ?? ''),
            'route' => (string) ($row['route_title'] ?? $row['route'] ?? ''),
            'interval' => (string) ($row['interval_title'] ?? $row['interval'] ?? ''),
            'active' => (string) ($row['active'] ?? ''),
            'start_date' => (string) ($row['start_date'] ?? ''),
            'end_date' => (string) ($row['end_date'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeVitalsPanel(array $row): array
    {
        return [
            'uuid' => (string) ($row['uuid'] ?? ''),
            'date' => (string) ($row['date'] ?? ''),
            'bps' => (string) ($row['bps'] ?? ''),
            'bpd' => (string) ($row['bpd'] ?? ''),
            'pulse' => (string) ($row['pulse'] ?? ''),
            'temperature' => (string) ($row['temperature'] ?? ''),
            'respiration' => (string) ($row['respiration'] ?? ''),
            'oxygen_saturation' => (string) ($row['oxygen_saturation'] ?? ''),
            'weight' => (string) ($row['weight'] ?? ''),
            'height' => (string) ($row['height'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function normalizeLaboratorySummary(array $order): array
    {
        return [
            'order_uuid' => (string) ($order['order_uuid'] ?? $order['uuid'] ?? ''),
            'name' => (string) ($order['procedure_name'] ?? $order['name'] ?? ''),
            'status' => (string) ($order['order_status'] ?? $order['status'] ?? ''),
            'date_ordered' => (string) ($order['date_ordered'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function normalizeProcedureOrder(array $order): array
    {
        return [
            'order_uuid' => (string) ($order['order_uuid'] ?? $order['uuid'] ?? ''),
            'procedure_name' => (string) ($order['procedure_name'] ?? $order['name'] ?? ''),
            'order_status' => (string) ($order['order_status'] ?? $order['status'] ?? ''),
            'date_ordered' => (string) ($order['date_ordered'] ?? ''),
            'order_type' => (string) ($order['procedure_order_type'] ?? $order['order_type'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $n
     * @return array<string, mixed>
     */
    private function normalizeSoapNote(array $n): array
    {
        return [
            'id' => (string) ($n['id'] ?? ''),
            'subjective' => (string) ($n['subjective'] ?? ''),
            'objective' => (string) ($n['objective'] ?? ''),
            'assessment' => (string) ($n['assessment'] ?? ''),
            'plan' => (string) ($n['plan'] ?? ''),
        ];
    }
}
