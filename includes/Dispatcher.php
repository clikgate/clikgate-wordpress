<?php
/**
 * Integrations hand their confirmed results here. The dispatcher checks them, maps the gateway to a provider
 * and the record to a goal, and queues either:
 * - POST /v1/plugin/payments for a Payment goal or no goal (Clikgate decides the goal); or
 * - POST /v1/plugin/goals for a successful result mapped to a Standard goal (`kind: standard`).
 *
 * Filter `clikgate_confirmation` (Confirmation|null, integration id) changes or drops a result.
 */

declare(strict_types=1);

namespace Clikgate\WP;

if (!defined('ABSPATH')) {
    exit;
}

final class Dispatcher
{
    /** @return string queued | skipped | duplicate */
    public function confirm(Confirmation $confirmation): string
    {
        $filtered = apply_filters('clikgate_confirmation', $confirmation, $confirmation->source_plugin);
        if (!$filtered instanceof Confirmation) {
            return 'skipped';
        }
        $c = $filtered;
        $label = [
            'source' => $c->source_plugin,
            'order_id' => $c->order_id,
            'status' => $c->status,
            'amount' => Money::positive($c->amount),
            'currency' => Money::currency($c->currency),
        ];
        if (!Connection::connected()) {
            return 'skipped';
        }
        if (!Options::integration_enabled($c->source_plugin)) {
            return 'skipped';
        }
        $problem = $c->problem();
        if ($problem !== null) {
            Log::add('skipped', $label + ['code' => $problem]);
            return 'skipped';
        }
        // Known gateways get their provider key; manual and unknown ones `custom`
        $provider = Providers::resolve($c->gateway, $c->source_plugin);
        if ($provider === null) {
            Log::add('skipped', $label + ['code' => 'unknown_gateway', 'message' => $c->gateway]);
            return 'skipped';
        }
        $label['provider'] = $provider;
        // A gateway test payment on a live connection (or the reverse) would be refused: never send it
        if ($c->test !== null && ($c->test ? 'test' : 'live') !== Connection::mode()) {
            Log::add('skipped', $label + ['code' => 'environment_mismatch']);
            return 'skipped';
        }
        $goal = Goals::resolve($c->source_plugin, $c->items);
        $label['goal'] = $goal;
        if (Goals::is_plain_goal($goal)) {
            if ($c->status !== 'success') {
                // Standard goals count when sent: only successful payments go there
                return 'skipped';
            }
            $queued = Queue::push('/v1/plugin/goals', $c->goal_body((string) $goal), $c->dedupe_key(), $label);
        } else {
            $queued = Queue::push('/v1/plugin/payments', $c->payment_body($provider, $goal, Connection::private_mode()), $c->dedupe_key(), $label);
        }
        return $queued ? 'queued' : 'duplicate';
    }
}
