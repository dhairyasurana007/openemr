<?php

/**
 * Read-only retrieval bridge for Clinical Co-Pilot (stable JSON + citations).
 *
 * Implements the six schema-bound retrieval tools described in project
 * architecture: schedule slots, patient core profile, medications, observations
 * (vitals + laboratory), encounters with notes, and referrals/orders/care gaps.
 * Optional narrow routes: vitals-only and laboratory-results-only.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\RestControllers\ClinicalCopilot;

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
