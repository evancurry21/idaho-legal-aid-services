<?php

declare(strict_types=1);

/**
 * Pantheon Quicksilver hook: send a New Relic change-tracking deployment.
 *
 * This script is intentionally fail-closed. If the required API key or entity
 * GUIDs are absent, it exits successfully without sending anything.
 */

$apiKey = getenv('NEW_RELIC_API_KEY') ?: '';
$entityGuids = array_values(array_filter(array_map(
  'trim',
  explode(',', implode(',', array_filter([
    getenv('NEW_RELIC_ENTITY_GUID_APM') ?: '',
    getenv('NEW_RELIC_ENTITY_GUID_BROWSER') ?: '',
  ])))
)));

if ($apiKey === '' || $entityGuids === []) {
  fwrite(STDERR, "new-relic-change-tracking: skipped (missing API key or entity GUIDs)\n");
  exit(0);
}

$siteName = getenv('PANTHEON_SITE_NAME') ?: '<PANTHEON_SITE_NAME>';
$siteId = getenv('PANTHEON_SITE_ID') ?: '<PANTHEON_SITE_ID>';
$pantheonEnv = getenv('PANTHEON_ENVIRONMENT') ?: 'local';
$deploymentId = getenv('PANTHEON_DEPLOYMENT_IDENTIFIER') ?: '';
$gitSha = getenv('GITHUB_SHA') ?: (getenv('SOURCE_VERSION') ?: '');
$release = $deploymentId !== '' ? $deploymentId : ($gitSha !== '' ? $gitSha : 'unknown');
$deepLink = $siteId !== '<PANTHEON_SITE_ID>'
  ? sprintf('https://dashboard.pantheon.io/sites/%s#%s/code', $siteId, $pantheonEnv)
  : '';
$actor = getenv('PANTHEON_DEPLOYING_EMAIL') ?: sprintf('%s.%s', $siteName, $pantheonEnv);
$timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);

$mutation = <<<'GRAPHQL'
mutation ChangeTrackingCreateDeployment($deployment: ChangeTrackingDeploymentInput!) {
  changeTrackingCreateDeployment(deployment: $deployment) {
    deploymentId
    entityGuid
  }
}
GRAPHQL;

$endpoint = 'https://api.newrelic.com/graphql';

foreach ($entityGuids as $entityGuid) {
  $payload = [
    'query' => $mutation,
    'variables' => [
      'deployment' => [
        'entityGuid' => $entityGuid,
        'version' => $release,
        'commit' => $gitSha,
        'groupId' => sprintf('%s:%s', $siteName, $pantheonEnv),
        'description' => sprintf('Pantheon deploy for %s.%s', $siteName, $pantheonEnv),
        'deepLink' => $deepLink,
        'timestamp' => $timestamp,
        'user' => $actor,
      ],
    ],
  ];

  $ch = curl_init($endpoint);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'API-Key: ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
    CURLOPT_TIMEOUT => 10,
  ]);

  $response = curl_exec($ch);
  $curlError = curl_error($ch);
  $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($response === false) {
    fwrite(STDERR, "new-relic-change-tracking: curl failed for {$entityGuid}: {$curlError}\n");
    continue;
  }

  if ($statusCode < 200 || $statusCode >= 300) {
    fwrite(STDERR, "new-relic-change-tracking: HTTP {$statusCode} for {$entityGuid}: {$response}\n");
    continue;
  }

  fwrite(STDERR, "new-relic-change-tracking: recorded deployment for {$entityGuid} release {$release}\n");
}
