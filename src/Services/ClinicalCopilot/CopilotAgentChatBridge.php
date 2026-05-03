<?php

/**
 * Forwards a clinician message from OpenEMR web to the copilot-agent service (OpenRouter there).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Services\ClinicalCopilot;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OpenEMR\RestControllers\ClinicalCopilot\ClinicalCopilotInternalAuth;

final class CopilotAgentChatBridge
{
    /**
     * @return array{reply: string}
     *
     * @throws \DomainException When the agent base URL is not configured.
     * @throws \RuntimeException When the agent returns an error or malformed JSON.
     */
    public function forwardMessage(string $message, AgentRuntimeHandoff $handoff): array
    {
        $payload = new ClinicalCopilotAgentChatPayload(
            message: $message,
            useCase: ClinicalCopilotUseCase::UC4,
            httpTimeoutOverrideSeconds: 120.0,
        );

        return $this->forwardPayload($payload, $handoff);
    }

    /**
     * @return array{reply: string}
     *
     * @throws \DomainException When the agent base URL is not configured.
     * @throws \RuntimeException When the agent returns an error or malformed JSON.
     */
    public function forwardPayload(ClinicalCopilotAgentChatPayload $payload, AgentRuntimeHandoff $handoff): array
    {
        $base = $handoff->privateAgentBaseUrl;
        if ($base === '') {
            throw new \DomainException('Clinical co-pilot agent URL is not configured');
        }

        $url = rtrim($base, '/') . '/v1/chat';
        $secret = $this->readInternalSecret();
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        if ($secret !== '') {
            $headers[ClinicalCopilotInternalAuth::HEADER_NAME] = $secret;
        }

        $timeout = $payload->effectiveHttpTimeoutSeconds();
        $client = new Client(['timeout' => $timeout]);

        try {
            $response = $client->post($url, [
                'headers' => $headers,
                'json' => $payload->toAgentJsonArray(),
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Unable to reach the clinical co-pilot agent.', 0, $e);
        }

        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        if ($status >= 400 || !is_array($decoded)) {
            throw new \RuntimeException('Clinical co-pilot agent returned an error.');
        }

        if (!isset($decoded['reply']) || !is_string($decoded['reply'])) {
            throw new \RuntimeException('Clinical co-pilot agent returned an unexpected response.');
        }

        return ['reply' => $decoded['reply']];
    }

    private function readInternalSecret(): string
    {
        $raw = getenv('CLINICAL_COPILOT_INTERNAL_SECRET');
        if ($raw === false || $raw === '') {
            return '';
        }

        return trim((string) $raw);
    }
}
