<?php
/**
 * Human-readable API documentation page.
 *
 * Admin-only. Renders api/openapi.yaml using Swagger UI loaded from a CDN.
 * The spec itself is fetched by Swagger UI from /api/v1/openapi.yaml so the
 * browser sees the same authoritative source the chatbot uses.
 */

require __DIR__ . '/../../session_config.php';
require __DIR__ . '/../../dbcon.php';
require_once __DIR__ . '/../../services/openapi_loader.php';

if (!isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
    $_SESSION['message'] = 'Unauthorized: admin role required.';
    header('Location: ../../index.php');
    exit;
}

$version = 'unknown';
try {
    $spec = mv_openapi_load();
    $version = (string)($spec['info']['version'] ?? 'unknown');
} catch (Throwable $e) {
    error_log('api/docs spec load error: ' . $e->getMessage());
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>MyVivarium API Documentation</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist/swagger-ui.css">
<style>
  body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
  .mv-banner {
    background: #14532d;
    color: white;
    padding: 12px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 14px;
  }
  .mv-banner a { color: #d1fae5; text-decoration: underline; }
  .mv-banner a:hover { color: white; }
  .mv-version { font-family: monospace; opacity: 0.85; }
  #swagger-ui { padding-top: 8px; }
</style>
</head>
<body>
<div class="mv-banner">
  <span>
    <strong>MyVivarium API Documentation</strong>
    &nbsp;&middot;&nbsp;
    <span class="mv-version">version <?= htmlspecialchars($version) ?></span>
  </span>
  <span>
    <a href="../../manage_api_keys.php">API Keys</a>
    &nbsp;&middot;&nbsp;
    <a href="../../manage_ai_config.php">AI Configuration</a>
    &nbsp;&middot;&nbsp;
    <a href="../../home.php">Back to Admin</a>
  </span>
</div>
<div id="swagger-ui"></div>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist/swagger-ui-bundle.js" crossorigin></script>
<script>
  window.onload = function() {
    window.ui = SwaggerUIBundle({
      url: '/api/v1/openapi.yaml',
      dom_id: '#swagger-ui',
      deepLinking: true,
      presets: [SwaggerUIBundle.presets.apis],
      layout: 'BaseLayout',
    });
  };
</script>
</body>
</html>
