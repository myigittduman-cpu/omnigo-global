<?php
declare(strict_types=1);
// CLI only. Reads credentials from stdin, performs one sandbox GET, never opens a database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap.php';
use Omnigo\{HepsiburadaCredentials, HepsiburadaEnvironment, HepsiburadaReadClient, HepsiburadaPreview, SyncFailure};

$report = ['environment' => 'sandbox', 'readOnly' => true, 'productionChanged' => false, 'checkedAt' => gmdate('c')];
try {
    $input = json_decode((string) stream_get_contents(STDIN, 10000), true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException();
    $credentials = HepsiburadaCredentials::fromArray($input);
    unset($input);
    $reader = new HepsiburadaReadClient(environment: HepsiburadaEnvironment::Sandbox);
    $data = $reader->openOrders($credentials, time() + 25);
    $report += ['apiReadSucceeded' => true, 'totalCount' => $data['totalCount'], 'sampleCount' => count($data['items'])];
    $preview = HepsiburadaPreview::map($data['items'], $credentials->merchantId);
    $report += ['previewValidated' => true, 'merchantVerifiedFromOrders' => $preview['merchantVerified']];
    unset($credentials, $data, $preview);
} catch (SyncFailure $error) {
    $report += ['apiReadSucceeded' => false, 'checkCode' => $error->httpStatus, 'retryAfterSeconds' => $error->retryAfter];
} catch (Throwable) {
    $report += ['apiReadSucceeded' => false, 'checkCode' => 'invalid_input_or_runtime'];
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
exit(($report['apiReadSucceeded'] ?? false) && ($report['previewValidated'] ?? false) ? 0 : 1);
