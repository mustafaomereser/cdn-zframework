<?php

namespace App\Cdn;

use App\Models\Cdn\Webhooks;
use zFramework\Core\Facades\cURL;
use zFramework\Core\Facades\Defer;
use zFramework\Core\Facades\DB;

/**
 * Outbound notifications.
 *
 * Sent after the response, never during it: a webhook receiver that takes eight
 * seconds to answer must not make an upload take eight seconds. The body is
 * signed with the hook's secret, because a URL that anyone can POST to is not a
 * notification, it is an input.
 */
class Webhook
{
    /**
     * Queue a notification for whichever hooks are listening.
     *
     * @param array  $bucket
     * @param string $event
     * @param array  $payload
     * @return void
     */
    public static function fire(array $bucket, string $event, array $payload = []): void
    {
        if (!Support::config('webhooks.enabled', true)) return;

        try {
            $hooks = (new Webhooks)
                ->where('project_id', $bucket['project_id'] ?? 0)
                ->where('status', 'active')
                ->closureMode(false)
                ->get();
        } catch (\Throwable) {
            # The table may not exist yet on a half-migrated install. A missing
            # webhook is not worth failing an upload over.
            return;
        }

        foreach ($hooks as $hook) {
            # A hook bound to one bucket ignores the others.
            if (!empty($hook['bucket_id']) && (int) $hook['bucket_id'] !== (int) ($bucket['id'] ?? 0)) continue;

            $events = Support::json($hook['events']);
            if (count($events) && !in_array($event, $events, true) && !in_array('*', $events, true)) continue;

            Defer::after(fn() => self::deliver($hook, $event, $payload), 'cdn-webhook');
        }
    }

    /**
     * POST one notification.
     *
     * @param array  $hook
     * @param string $event
     * @param array  $payload
     * @return bool
     */
    public static function deliver(array $hook, string $event, array $payload): bool
    {
        $body = json_encode([
            'event'     => $event,
            'timestamp' => time(),
            'data'      => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        # Signed over the exact bytes sent. The receiver recomputes it from the
        # raw body - not from a re-encoded parse of it, which would differ.
        $signature = hash_hmac('sha256', $body, (string) $hook['secret']);

        try {
            Fetcher::guard($hook['url'], ['schemes' => ['https', 'http'], 'block-private' => false]);
        } catch (\InvalidArgumentException $e) {
            self::mark($hook, 0, $e->getMessage());
            return false;
        }

        $result = cURL::set($hook['url'])->options([
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) Support::config('webhooks.timeout', 8),
            CURLOPT_CONNECTTIMEOUT => (int) Support::config('webhooks.timeout', 8),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Cdn-Event: ' . $event,
                'X-Cdn-Signature: sha256=' . $signature,
                'User-Agent: zFramework-CDN/1.0',
            ],
        ])->send(fn($response, $info, $error) => ['status' => (int) ($info['http_code'] ?? 0), 'error' => $error['error_message'] ?? null]);

        $status = (int) ($result['status'] ?? 0);
        $ok     = $status >= 200 && $status < 300;

        self::mark($hook, $status, $ok ? null : (string) ($result['error'] ?: "http-$status"));

        return $ok;
    }

    /**
     * Record the outcome, and stop calling a hook that has been failing.
     *
     * @param array       $hook
     * @param int         $status
     * @param string|null $error
     * @return void
     */
    private static function mark(array $hook, int $status, ?string $error): void
    {
        try {
            $failures = $error === null ? 0 : (int) $hook['failures'] + 1;
            $limit    = (int) Support::config('webhooks.retries', 3);

            (new DB)->prepare(
                "UPDATE cdn_webhooks
                    SET last_status = :status, last_error = :error, last_called_at = :now,
                        failures = :failures, status = :state
                  WHERE id = :id",
                [
                    'status'   => $status ?: null,
                    'error'    => $error === null ? null : mb_substr($error, 0, 255),
                    'now'      => date('Y-m-d H:i:s'),
                    'failures' => $failures,
                    # Disabled rather than deleted: the panel then shows why it
                    # stopped, which "the hook vanished" would not.
                    'state'    => $limit > 0 && $failures > $limit ? 'failing' : 'active',
                    'id'       => $hook['id'],
                ]
            );
        } catch (\Throwable) {
        }
    }
}
