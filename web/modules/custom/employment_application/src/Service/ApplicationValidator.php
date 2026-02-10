<?php

namespace Drupal\employment_application\Service;

use Drupal\Component\Utility\Xss;

/**
 * Validation and ID generation for employment applications.
 *
 * Extracted from the controller so validation logic can be unit-tested
 * against the real production code (not re-implemented in tests).
 */
class ApplicationValidator {

  /**
   * File upload configuration.
   */
  public const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx'];
  public const MAX_FILE_SIZE = 5242880; // 5MB
  public const MAX_TOTAL_UPLOAD_SIZE = 26214400; // 25MB

  /**
   * MIME types accepted per extension.
   *
   * Pantheon may report doc/docx as application/octet-stream, so we allow
   * that fallback but add magic-byte verification for octet-stream files.
   */
  public const ALLOWED_MIME_TYPES = [
    'pdf' => [
      'application/pdf',
      'application/octet-stream',
    ],
    'doc' => [
      'application/msword',
      'application/octet-stream',
    ],
    'docx' => [
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'application/octet-stream',
    ],
  ];

  /**
   * Magic bytes for file type sniffing when MIME is octet-stream.
   */
  private const MAGIC_BYTES = [
    'pdf'  => '%PDF',
    'docx' => "PK\x03\x04",
    'doc'  => "\xD0\xCF\x11\xE0",
  ];

  /**
   * Position family labels for display.
   */
  public const POSITION_FAMILY_LABELS = [
    'managing_attorney' => 'Managing Attorney',
    'staff_attorney' => 'Staff Attorney',
    'paralegal' => 'Paralegal',
    'legal_assistant' => 'Legal Assistant',
    'administrative' => 'Administrative Support',
    'outreach' => 'Community Outreach',
  ];

  /**
   * Minimum seconds between form render and submission.
   */
  public const MIN_FORM_TIME_SECONDS = 3;

  /**
   * Minimum phone digit count.
   */
  public const MIN_PHONE_DIGITS = 7;

  /**
   * Maximum phone digit count (E.164 max is 15).
   */
  public const MAX_PHONE_DIGITS = 15;

  /**
   * Generates a human-readable application ID with collision resistance.
   *
   * Format: "LastName, FirstName - JobTitle (YYYY-MM-DD HH-MM-SS-RANDOM)"
   * The random suffix prevents collisions when two applicants with the same
   * name apply for the same job within the same second.
   *
   * @param array $data
   *   Form data with 'full_name' and 'job_title' keys.
   *
   * @return string
   *   Application ID, max 191 characters.
   */
  public function generateApplicationId(array $data): string {
    $fullName = trim($data['full_name'] ?? 'Unknown');
    $nameParts = explode(' ', $fullName);

    $firstName = $nameParts[0] ?? 'Unknown';
    $lastName = count($nameParts) > 1 ? end($nameParts) : 'Unknown';

    $jobTitle = $data['job_title'] ?? 'Unknown Position';
    $dateSubmitted = date('Y-m-d H-i-s');
    $suffix = bin2hex(random_bytes(3));

    $applicationId = "{$lastName}, {$firstName} - {$jobTitle} ({$dateSubmitted}-{$suffix})";

    if (strlen($applicationId) > 191) {
      // Truncate the job title portion to preserve the unique suffix.
      $prefix = "{$lastName}, {$firstName} - ";
      $dateSuffix = " ({$dateSubmitted}-{$suffix})";
      $maxTitleLen = 191 - strlen($prefix) - strlen($dateSuffix);
      if ($maxTitleLen > 0) {
        $jobTitle = substr($jobTitle, 0, $maxTitleLen);
      }
      else {
        $jobTitle = '';
      }
      $applicationId = $prefix . $jobTitle . $dateSuffix;
      $applicationId = substr($applicationId, 0, 191);
    }

    return $applicationId;
  }

  /**
   * Validates an email address.
   *
   * @param string $email
   *   The email to validate.
   *
   * @return bool
   *   TRUE if valid.
   */
  public function validateEmail(string $email): bool {
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
  }

  /**
   * Validates a phone number.
   *
   * Ensures the string contains between MIN_PHONE_DIGITS and MAX_PHONE_DIGITS
   * actual digits, regardless of formatting characters.
   *
   * @param string $phone
   *   The phone number to validate.
   *
   * @return bool
   *   TRUE if valid.
   */
  public function validatePhone(string $phone): bool {
    $digitsOnly = preg_replace('/\D/', '', $phone);
    $digitCount = strlen($digitsOnly);

    return $digitCount >= self::MIN_PHONE_DIGITS
      && $digitCount <= self::MAX_PHONE_DIGITS;
  }

  /**
   * Validates a UUID format.
   *
   * @param string $uuid
   *   The UUID string.
   *
   * @return bool
   *   TRUE if valid UUID v4 format.
   */
  public function validateUuid(string $uuid): bool {
    return (bool) preg_match(
      '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i',
      $uuid
    );
  }

  /**
   * Validates a file's extension, MIME type, and size.
   *
   * @param string $extension
   *   Lowercase file extension.
   * @param string $mimeType
   *   MIME type as reported by the server.
   * @param int $size
   *   File size in bytes.
   * @param string|null $filePath
   *   Path to the physical file for magic-byte verification.
   *   Required when MIME is application/octet-stream.
   *
   * @return array
   *   ['valid' => bool, 'error' => string|null]
   */
  public function validateFile(string $extension, string $mimeType, int $size, ?string $filePath = NULL): array {
    // Size check.
    if ($size > self::MAX_FILE_SIZE) {
      return ['valid' => FALSE, 'error' => 'File size must be less than 5MB.'];
    }

    // Extension check.
    if (!in_array($extension, self::ALLOWED_EXTENSIONS, TRUE)) {
      return ['valid' => FALSE, 'error' => 'Only PDF, DOC, and DOCX files are allowed.'];
    }

    // MIME check.
    if (!isset(self::ALLOWED_MIME_TYPES[$extension])) {
      return ['valid' => FALSE, 'error' => 'Invalid file type.'];
    }

    if (!in_array($mimeType, self::ALLOWED_MIME_TYPES[$extension], TRUE)) {
      return ['valid' => FALSE, 'error' => 'Invalid file type.'];
    }

    // Magic-byte verification for octet-stream files.
    if ($mimeType === 'application/octet-stream' && $filePath !== NULL) {
      if (!$this->verifyMagicBytes($extension, $filePath)) {
        return ['valid' => FALSE, 'error' => 'File content does not match its extension.'];
      }
    }

    return ['valid' => TRUE, 'error' => NULL];
  }

  /**
   * Verifies file magic bytes match the claimed extension.
   *
   * @param string $extension
   *   The claimed file extension.
   * @param string $filePath
   *   Path to the physical file.
   *
   * @return bool
   *   TRUE if magic bytes match.
   */
  public function verifyMagicBytes(string $extension, string $filePath): bool {
    if (!isset(self::MAGIC_BYTES[$extension])) {
      // No magic bytes defined for this extension — allow.
      return TRUE;
    }

    $expectedBytes = self::MAGIC_BYTES[$extension];
    $length = strlen($expectedBytes);

    $handle = @fopen($filePath, 'rb');
    if (!$handle) {
      return FALSE;
    }

    $header = fread($handle, $length);
    fclose($handle);

    if ($header === FALSE || strlen($header) < $length) {
      return FALSE;
    }

    return $header === $expectedBytes;
  }

  /**
   * Sanitizes input data (scalar or array).
   *
   * Filters dangerous HTML using Drupal's Xss::filter() and trims
   * whitespace. For output safety, consumers must still use
   * htmlspecialchars() in HTML contexts.
   *
   * @param mixed $value
   *   The value to sanitize.
   *
   * @return string|array
   *   Sanitized value.
   */
  public function sanitizeInput($value): string|array {
    if (is_array($value)) {
      $sanitized = [];
      foreach ($value as $key => $val) {
        if (is_array($val) || is_scalar($val)) {
          $sanitized[$key] = $this->sanitizeInput($val);
        }
      }
      return $sanitized;
    }

    if (is_scalar($value)) {
      return Xss::filter(trim((string) $value));
    }

    return '';
  }

  /**
   * Generates a secure filename for uploaded files.
   *
   * @param string $originalName
   *   The original client filename.
   *
   * @return string
   *   Sanitized filename with timestamp and random suffix.
   */
  public function generateSecureFilename(string $originalName): string {
    $pathinfo = pathinfo($originalName);
    $extension = strtolower($pathinfo['extension'] ?? '');
    $basename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $pathinfo['filename'] ?? 'file');
    $timestamp = time();
    $random = bin2hex(random_bytes(4));

    return "{$basename}_{$timestamp}_{$random}.{$extension}";
  }

  /**
   * Formats position title for display.
   *
   * @param string $position
   *   The position family machine name.
   *
   * @return string
   *   Human-readable position label.
   */
  public function formatPositionTitle(string $position): string {
    if (isset(self::POSITION_FAMILY_LABELS[$position])) {
      return self::POSITION_FAMILY_LABELS[$position];
    }

    return ucwords(strtolower(str_replace('_', ' ', $position)));
  }

  /**
   * Formats Yes/No/Other values for display.
   *
   * @param string $value
   *   The raw value.
   *
   * @return string
   *   Human-readable label.
   */
  public function formatYesNoOption(string $value): string {
    return match (strtolower($value)) {
      'yes' => 'Yes',
      'no' => 'No',
      'licensed_other_state' => 'Licensed in Another State',
      default => ucfirst(str_replace('_', ' ', $value)),
    };
  }

  /**
   * Validates a US ZIP code format.
   *
   * Accepts 5-digit (12345) or ZIP+4 (12345-6789) formats.
   *
   * @param string $zip
   *   The ZIP code to validate.
   *
   * @return bool
   *   TRUE if valid US ZIP code format.
   */
  public function validateZipCode(string $zip): bool {
    return (bool) preg_match('/^\d{5}(-\d{4})?$/', trim($zip));
  }

  /**
   * Hashes an IP address for privacy-safe storage.
   *
   * Uses SHA-256 with a daily rotating salt derived from the Drupal hash salt.
   * This prevents reverse-lookup of IPs while still allowing same-day
   * correlation for abuse detection.
   *
   * @param string $ip
   *   The raw IP address.
   * @param string $hashSalt
   *   The site's hash salt (from settings.php).
   *
   * @return string
   *   64-character hex hash.
   */
  public function hashIp(string $ip, string $hashSalt): string {
    $dailySalt = date('Y-m-d') . $hashSalt;
    return hash('sha256', $ip . $dailySalt);
  }

}
