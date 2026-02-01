<?php

namespace Notifizz;

use GuzzleHttp\Client;
use Exception;

require_once __DIR__ . '/TrackContext.php';
class NotifizzClient
{
    private string $authSecretKey;
    private string $sdkSecretKey;
    private string $baseUrl = 'http://localhost:6001/v1';
    private string $algorithm = 'sha256';
    private array $options = [
        'autoSendDelayMs' => 1000,
    ];

    private static array $enrichFunctions = [];

    public function __construct(string $authSecretKey, string $sdkSecretKey)
    {
        $this->authSecretKey = $authSecretKey;
        $this->sdkSecretKey = $sdkSecretKey;
    }

    private function sha256(string $textToHash): string
    {
        return hash($this->algorithm, $textToHash);
    }

    public function generateHashedToken(string $userId): string
    {
        return $this->sha256($userId . $this->authSecretKey);
    }

    public static function configureEnrich($workflowIds, callable $enrichFn): void
    {
        $workflowIds = is_array($workflowIds) ? $workflowIds : [$workflowIds];
        foreach ($workflowIds as $workflowId) {
            self::$enrichFunctions[$workflowId][] = $enrichFn;
        }
    }

    public function track(array $props): TrackContext
    {
        return new TrackContext($props, $this->options, $this->sdkSecretKey);
    }

    public function config(array $opts): void
    {
        $this->options = array_merge($this->options, $opts);
    }

    public function send(array $request)
    {
        $client = new Client();

        try {
            $recipients = $request['properties']['recipients'] ?? [];
            unset($request['properties']['recipients']);

            $response = $client->post(
                "{$this->baseUrl}/notification/channel/notificationcenter/config/{$request['notifId']}/track",
                [
                    'headers' => [
                        'Authorization' => "Bearer {$this->sdkSecretKey}",
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'properties' => $request['properties'],
                        'recipients' => $recipients,
                    ],
                ]
            );

            return json_decode($response->getBody(), true);
        } catch (Exception $e) {
            error_log("Error: " . $e->getMessage());
            throw $e;
        }
    }
}
