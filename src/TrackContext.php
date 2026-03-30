<?php

namespace Notifizz;

use GuzzleHttp\Client;
use Exception;

class TrackContext
{
    private const DEFAULT_BASE_URL = 'https://eu.api.notifizz.com/v1';
    private const RETRY_DELAYS_MS = [1000, 2000];

    private array $event;
    private array $workflows = [];
    private bool $hasSent = false;
    private array $options;
    private string $sdkSecretKey;
    private string $baseUrl;

    public function __construct(array $event, array $options, string $sdkSecretKey, ?string $baseUrl = null)
    {
        $this->event = $event;
        $this->options = $options;
        $this->sdkSecretKey = $sdkSecretKey;
        $this->baseUrl = $baseUrl ?? self::DEFAULT_BASE_URL;
    }

    public function workflow(string $campaignId, array $recipients): self
    {
        if ($this->hasSent) {
            throw new Exception("Cannot add workflows after sending the event.");
        }

        $this->workflows[] = [
            'campaignId' => $campaignId,
            'recipients' => $recipients,
        ];

        return $this;
    }

    /** @deprecated Use track() instead. */
    public function send(): void
    {
        if ($this->hasSent) return;
        $this->hasSent = true;

        $client = new Client();

        $payload = array_merge($this->event, [
            'workflows' => $this->workflows,
            'sdkSecretKey' => $this->sdkSecretKey,
        ]);

        try {
            $client->post(
                "{$this->baseUrl}/events/track",
                [
                    'headers' => [
                        'Authorization' => "Bearer {$this->sdkSecretKey}",
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
                ]
            );
        } catch (Exception $e) {
            error_log('Error: ' . $e->getMessage());
            throw $e;
        }
    }

    public function track(): void
    {
        if ($this->hasSent) return;
        $this->hasSent = true;

        $idempotencyKey = $this->event['idempotencyKey'] ?? bin2hex(random_bytes(16));

        $payload = array_merge($this->event, [
            'workflows' => $this->workflows,
            'sdkSecretKey' => $this->sdkSecretKey,
            'idempotencyKey' => $idempotencyKey,
        ]);

        $client = new Client();
        $lastError = null;
        $maxRetries = count(self::RETRY_DELAYS_MS);

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $client->post(
                    "{$this->baseUrl}/events/track",
                    [
                        'headers' => [
                            'Authorization' => "Bearer {$this->sdkSecretKey}",
                            'Content-Type' => 'application/json',
                            'X-Idempotency-Key' => $idempotencyKey,
                        ],
                        'json' => $payload,
                    ]
                );
                return;
            } catch (Exception $e) {
                $lastError = $e;
                if ($attempt < $maxRetries) {
                    usleep(self::RETRY_DELAYS_MS[$attempt] * 1000);
                }
            }
        }

        throw $lastError;
    }
}
