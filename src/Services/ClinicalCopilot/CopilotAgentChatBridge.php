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
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use OpenEMR\RestControllers\ClinicalCopilot\ClinicalCopilotInternalAuth;

final class CopilotAgentChatBridge
{
    public function __construct(
        private readonly ClinicalCopilotAiAuditRepository $aiAuditRepository = new ClinicalCopilotAiAuditRepository(),
    ) {
    }

    /**
     * @return array{reply: string}
     *
     * @throws \DomainException When the agent base URL is not configured.
     * @throws \RuntimeException When the agent returns an error or malformed JSON.
     */
    public function forwardMessage(
        string $message,
        AgentRuntimeHandoff $handoff,
        ?ClinicalCopilotAgentChatAuditBinding $audit = null,
        string $requestId = '',
    ): array {
        $payload = new ClinicalCopilotAgentChatPayload(
            message: $message,
            useCase: ClinicalCopilotUseCase::UC4,
        );

        return $this->forwardPayload($payload, $handoff, $audit, $requestId);
    }

    /**
     * @return array{reply: string}
     *
     * @throws \DomainException When the agent base URL is not configured.
     * @throws \RuntimeException When the agent returns an error or malformed JSON.
     */
    public function forwardPayload(
        ClinicalCopilotAgentChatPayload $payload,
        AgentRuntimeHandoff $handoff,
        ?ClinicalCopilotAgentChatAuditBinding $audit = null,
        string $requestId = '',
    ): array {
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
        if ($requestId !== '') {
            $headers['X-Request-Id'] = $requestId;
        }

        $timeout = $payload->effectiveHttpTimeoutSeconds();
        $client = new Client([
            'timeout' => $timeout,
            'connect_timeout' => min(3.0, $timeout),
        ]);

        $start = microtime(true);
        try {
            $response = $this->postWithRetry($client, $url, $headers, $payload->toAgentJsonArray());
        } catch (GuzzleException $e) {
            $this->maybeRecordAudit(
                $audit,
                $payload->useCase,
                'transport_error',
                $this->elapsedMsSince($start),
                null,
                $e::class,
            );
            throw new \RuntimeException('Unable to reach the clinical co-pilot agent.', 0, $e);
        }

        $latencyMs = $this->elapsedMsSince($start);
        $status = $response->getStatusCode();
        $raw = (string) $response->getBody();
        $decoded = json_decode($raw, true);
        if ($status >= 400 || !is_array($decoded)) {
            $this->maybeRecordAudit($audit, $payload->useCase, 'agent_error', $latencyMs, $status, null);
            throw new \RuntimeException('Clinical co-pilot agent returned an error.');
        }

        if (!isset($decoded['reply']) || !is_string($decoded['reply'])) {
            $this->maybeRecordAudit($audit, $payload->useCase, 'agent_error', $latencyMs, $status, 'invalid_response_shape');
            throw new \RuntimeException('Clinical co-pilot agent returned an unexpected response.');
        }

        $this->maybeRecordAudit($audit, $payload->useCase, 'success', $latencyMs, $status, null);

        $meta = [];
        if (isset($decoded['tool_rounds_used'])) {
            $meta['tool_rounds_used'] = (int) $decoded['tool_rounds_used'];
        }
        if (isset($decoded['tool_payload_count'])) {
            $meta['tool_payload_count'] = (int) $decoded['tool_payload_count'];
        }
        if (isset($decoded['summarization_mode']) && is_string($decoded['summarization_mode'])) {
            $meta['summarization_mode'] = $decoded['summarization_mode'];
        }

        return ['reply' => $decoded['reply'], 'meta' => $meta];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $jsonBody
     * @throws GuzzleException
     */
    private function postWithRetry(Client $client, string $url, array $headers, array $jsonBody): \Psr\Http\Message\ResponseInterface
    {
        $attempt = 0;
        $maxAttempts = 2;
        $last = null;
        while ($attempt < $maxAttempts) {
            $attempt++;
            try {
                return $client->post($url, [
                    'headers' => $headers,
                    'json' => $jsonBody,
                ]);
            } catch (GuzzleException $e) {
                $last = $e;
                $isRetriable = $e instanceof ConnectException;
                if (!$isRetriable || $attempt >= $maxAttempts) {
                    throw $e;
                }
                usleep(200000);
            }
        }

        if ($last instanceof GuzzleException) {
            throw $last;
        }

        throw new \RuntimeException('Unexpected co-pilot bridge retry state.');
    }

    private function readInternalSecret(): string
    {
        $raw = getenv('CLINICAL_COPILOT_INTERNAL_SECRET');
        if ($raw === false || $raw === '') {
            return '';
        }

        return trim((string) $raw);
    }

    private function elapsedMsSince(float $startMonotonic): int
    {
        return (int) round((microtime(true) - $startMonotonic) * 1000.0);
    }

    /**
     * @param non-empty-string $outcome
     */
    private function maybeRecordAudit(
        ?ClinicalCopilotAgentChatAuditBinding $audit,
        ClinicalCopilotUseCase $useCase,
        string $outcome,
        int $latencyMs,
        ?int $httpStatus,
        ?string $errorClass,
    ): void {
        if ($audit === null) {
            return;
        }

        $this->aiAuditRepository->recordAgentChatMetadata(
            $audit,
            $useCase,
            'agent_chat_proxy',
            $outcome,
            $latencyMs,
            $httpStatus,
            $errorClass,
        );
    }
}
