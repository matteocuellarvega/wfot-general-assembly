<?php

require_once __DIR__ . '/../../src/bootstrap.php';

use WFOT\Repository\RegistrationRepository;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

function respondError(int $statusCode, string $message): void
{
	http_response_code($statusCode);
	header('Content-Type: application/json');
	echo json_encode(['error' => $message]);
	exit;
}

function sanitizeRecordId(?string $value): ?string
{
	if (!$value) {
		return null;
	}
	$clean = preg_replace('/[^a-zA-Z0-9]/', '', $value);
	return $clean ?: null;
}

function parseHexColor(?string $value): ?Color
{
	if (!$value) {
		return null;
	}
	$hex = ltrim(trim($value), '#');
	if (preg_match('/^[0-9a-fA-F]{3}$/', $hex)) {
		$hex = sprintf('%s%s%s%s%s%s', $hex[0], $hex[0], $hex[1], $hex[1], $hex[2], $hex[2]);
	}
	if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
		return null;
	}
	$r = hexdec(substr($hex, 0, 2));
	$g = hexdec(substr($hex, 2, 2));
	$b = hexdec(substr($hex, 4, 2));
	return new Color($r, $g, $b);
}

$registrationId = sanitizeRecordId($_GET['registrationId'] ?? null);
$fgColor = parseHexColor($_GET['fg'] ?? null);
$bgColor = parseHexColor($_GET['bg'] ?? null);

if (!$registrationId) {
	respondError(400, 'Missing registrationId.');
}
if (!$fgColor || !$bgColor) {
	respondError(400, 'Invalid fg or bg color.');
}

$registrationRepo = new RegistrationRepository();
$registration = $registrationRepo->find($registrationId);
if (!$registration) {
	respondError(404, 'Registration not found.');
}

$attending = $registration['fields']['Attending'] ?? null;
if (strcasecmp((string) $attending, 'Yes') !== 0) {
	respondError(403, 'Registration not attending.');
}

$payload = json_encode(['registrationId' => $registrationId], JSON_UNESCAPED_SLASHES);
if ($payload === false) {
	respondError(500, 'Failed to build QR payload.');
}

$builder = new Builder(
	writer: new PngWriter(),
	writerOptions: [],
	data: $payload,
	encoding: new Encoding('UTF-8'),
	errorCorrectionLevel: ErrorCorrectionLevel::High,
	size: 300,
	margin: 10,
	roundBlockSizeMode: RoundBlockSizeMode::Margin,
	foregroundColor: $fgColor,
	backgroundColor: $bgColor
);

$result = $builder->build();

header('Content-Type: ' . $result->getMimeType());
echo $result->getString();
