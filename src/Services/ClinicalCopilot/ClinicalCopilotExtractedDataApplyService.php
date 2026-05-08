<?php

/**
 * Validates and maps confirmed extraction payloads into core OpenEMR tables.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\ClinicalCopilot;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Services\VitalsService;

final class ClinicalCopilotExtractedDataApplyService
{
    /**
     * @param array<string, mixed> $extractedFacts
     * @return array{vitals_form_id: int|null, pnote_id: int}
     */
    public function applyConfirmedExtraction(
        int $pid,
        ?int $encounter,
        string $docType,
        string $authUser,
        string $authProvider,
        int $authUserId,
        array $extractedFacts
    ): array {
        $vitalsFormId = null;
        QueryUtils::startTransaction();
        try {
            if ($encounter !== null) {
                $vitalsPayload = $this->buildVitalsPayload($pid, $encounter, $authUser, $authProvider, $extractedFacts);
                if ($vitalsPayload !== null) {
                    $savedVitals = (new VitalsService())->saveVitalsArray($vitalsPayload);
                    $savedId = $savedVitals['id'] ?? null;
                    if (is_numeric($savedId)) {
                        $vitalsFormId = (int) $savedId;
                    }
                }
            }

            $pnoteId = $this->insertPnote($pid, $docType, $authUser, $authProvider, $authUserId, $extractedFacts, $vitalsFormId);
            QueryUtils::commitTransaction();
            return ['vitals_form_id' => $vitalsFormId, 'pnote_id' => $pnoteId];
        } catch (\Throwable $throwable) {
            QueryUtils::rollbackTransaction();
            throw $throwable;
        }
    }

    /**
     * @param array<string, mixed> $facts
     * @return array<string, mixed>|null
     */
    private function buildVitalsPayload(int $pid, int $encounter, string $authUser, string $authProvider, array $facts): ?array
    {
        $bps = $this->extractFloat($facts, ['systolic', 'bp_systolic', 'blood_pressure_systolic', 'systolic_bp']);
        $bpd = $this->extractFloat($facts, ['diastolic', 'bp_diastolic', 'blood_pressure_diastolic', 'diastolic_bp']);
        $weight = $this->extractFloat($facts, ['weight', 'weight_lb', 'body_weight']);
        $height = $this->extractFloat($facts, ['height', 'height_in', 'body_height']);
        $temperature = $this->extractFloat($facts, ['temperature', 'temp', 'body_temperature']);
        $pulse = $this->extractFloat($facts, ['pulse', 'heart_rate', 'hr']);
        $respiration = $this->extractFloat($facts, ['respiration', 'respiratory_rate', 'rr']);
        $oxygenSaturation = $this->extractFloat($facts, ['oxygen_saturation', 'spo2', 'o2_sat']);

        $bpCombined = $this->extractString($facts, ['blood_pressure', 'bp']);
        if (($bps === null || $bpd === null) && $bpCombined !== null) {
            $bpPieces = $this->parseBp($bpCombined);
            if ($bps === null) {
                $bps = $bpPieces['bps'];
            }
            if ($bpd === null) {
                $bpd = $bpPieces['bpd'];
            }
        }

        if ($bps === null && $bpd === null && $weight === null && $height === null && $temperature === null
            && $pulse === null && $respiration === null && $oxygenSaturation === null) {
            return null;
        }

        $payload = [
            'id' => null,
            'eid' => $encounter,
            'pid' => $pid,
            'user' => $authUser,
            'groupname' => $authProvider,
            'authorized' => 1,
            'activity' => 1,
            'date' => date('Y-m-d H:i:s'),
        ];
        if ($bps !== null) {
            $payload['bps'] = (string) $bps;
        }
        if ($bpd !== null) {
            $payload['bpd'] = (string) $bpd;
        }
        if ($weight !== null) {
            $payload['weight'] = $weight;
        }
        if ($height !== null) {
            $payload['height'] = $height;
        }
        if ($temperature !== null) {
            $payload['temperature'] = $temperature;
        }
        if ($pulse !== null) {
            $payload['pulse'] = $pulse;
        }
        if ($respiration !== null) {
            $payload['respiration'] = $respiration;
        }
        if ($oxygenSaturation !== null) {
            $payload['oxygen_saturation'] = $oxygenSaturation;
        }
        return $payload;
    }

    /**
     * @param array<string, mixed> $facts
     */
    private function insertPnote(
        int $pid,
        string $docType,
        string $authUser,
        string $authProvider,
        int $authUserId,
        array $facts,
        ?int $vitalsFormId
    ): int {
        $title = $docType === 'lab'
            ? 'Clinical Co-Pilot Lab Extraction'
            : 'Clinical Co-Pilot Intake Extraction';

        $summary = [
            'doc_type' => $docType,
            'imported_at' => date('c'),
            'vitals_form_id' => $vitalsFormId,
            'extracted_facts' => $facts,
        ];
        $body = date('Y-m-d H:i') . ' (' . $authUser . ') '
            . 'Clinician-confirmed extraction imported:' . "\n"
            . json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $sql = 'INSERT INTO pnotes (date, body, pid, user, groupname, authorized, activity, title, assigned_to, message_status, update_by, update_date)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
        $id = QueryUtils::sqlInsert($sql, [
            date('Y-m-d H:i:s'),
            $body,
            $pid,
            $authUser,
            $authProvider,
            1,
            1,
            $title,
            '',
            'New',
            $authUserId,
        ]);
        return (int) $id;
    }

    /**
     * @param array<string, mixed> $facts
     * @param list<string> $keyCandidates
     */
    private function extractString(array $facts, array $keyCandidates): ?string
    {
        $flattened = $this->flatten($facts);
        foreach ($flattened as $key => $value) {
            $lastSegment = strrchr($key, '.');
            $normalizedLast = $this->normalizeKey($lastSegment === false ? $key : substr($lastSegment, 1));
            foreach ($keyCandidates as $candidate) {
                if ($normalizedLast !== $this->normalizeKey($candidate)) {
                    continue;
                }
                if (is_scalar($value)) {
                    $text = trim((string) $value);
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $facts
     * @param list<string> $keyCandidates
     */
    private function extractFloat(array $facts, array $keyCandidates): ?float
    {
        $raw = $this->extractString($facts, $keyCandidates);
        if ($raw === null) {
            return null;
        }
        $cleaned = preg_replace('/[^0-9.\-]/', '', $raw);
        if (!is_string($cleaned) || $cleaned === '' || !is_numeric($cleaned)) {
            return null;
        }
        return (float) $cleaned;
    }

    /**
     * @return array{bps: float|null, bpd: float|null}
     */
    private function parseBp(string $input): array
    {
        if (!preg_match('/^\s*(\d{2,3})\s*\/\s*(\d{2,3})\s*$/', $input, $matches)) {
            return ['bps' => null, 'bpd' => null];
        }
        return ['bps' => (float) $matches[1], 'bpd' => (float) $matches[2]];
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/', '', $key) ?? '');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function flatten(array $data): array
    {
        $result = [];
        $stack = [['prefix' => '', 'value' => $data]];
        while ($stack !== []) {
            $frame = array_pop($stack);
            if (!is_array($frame)) {
                continue;
            }
            $prefix = is_string($frame['prefix'] ?? null) ? $frame['prefix'] : '';
            $value = $frame['value'] ?? null;
            if (!is_array($value)) {
                if ($prefix !== '') {
                    $result[$prefix] = $value;
                }
                continue;
            }
            foreach ($value as $key => $child) {
                $childKey = is_int($key) ? (string) $key : $key;
                $nextPrefix = $prefix === '' ? $childKey : ($prefix . '.' . $childKey);
                if (is_array($child)) {
                    $stack[] = ['prefix' => $nextPrefix, 'value' => $child];
                } else {
                    $result[$nextPrefix] = $child;
                }
            }
        }
        return $result;
    }
}
