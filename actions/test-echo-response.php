<?php
/**
 * Test action: sets custom fields in $actionResponse.
 * Used by integration tests for action response override feature.
 *
 * Receives: $action, $submission, $config, $actionResponse (by reference via scope)
 */
$prefix = $action['order_prefix'] ?? 'TEST';
$actionResponse['order_id'] = $prefix . '-' . strtoupper(substr($submission['id'], 4, 8));
$actionResponse['redirect'] = '/thank-you?order=' . $actionResponse['order_id'];
