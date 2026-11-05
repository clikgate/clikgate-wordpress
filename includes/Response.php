<?php

declare(strict_types=1);

namespace Clikgate\WP;

if (!defined('ABSPATH')) {
    exit;
}

/** One API answer: status 0 is a network error. `code` is the error envelope's code. */
final class Response
{
    /** The plan doesn't include Payment goals (older Clikgate versions answered `plan_upgrade_required`). */
    public const PLAN_CODES = ['plan_feature_unavailable', 'plan_upgrade_required'];

    /** @param array<string, mixed> $body */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
        public readonly ?int $retry_after = null
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** 5xx, 408, 429 and network errors are retried; other 4xx are final (as in Clikgate's SDKs). */
    public function retryable(): bool
    {
        return $this->status === 0 || $this->status === 408 || $this->status === 429 || $this->status >= 500;
    }

    /**
     * Clikgate refuses every result from this site until the admin acts: the plan has no Payment goals
     * (`403 plan_feature_unavailable`, Starter and up) or the key isn't a plugin key (`403 insufficient_scope`).
     * Results stay queued.
     */
    public function blocked(): bool
    {
        return $this->status === 403 && in_array($this->code, [...self::PLAN_CODES, 'insufficient_scope'], true);
    }

    public static function plan_code(string $code): bool
    {
        return in_array($code, self::PLAN_CODES, true);
    }

    /** The key was revoked or the website disconnected in the dashboard. */
    public function disconnected(): bool
    {
        return ($this->status === 403 && $this->code === 'plugin_disconnected')
            || ($this->status === 401 && $this->code === 'invalid_api_key');
    }
}
