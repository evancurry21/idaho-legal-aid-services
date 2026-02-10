<?php

namespace Drupal\Tests\employment_application\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Drupal\employment_application\Service\ApplicationValidator;

/**
 * Tests for the ApplicationValidator service.
 *
 * Exercises the real production service class — no re-implementation.
 * Uses PHPUnit\Framework\TestCase directly since ApplicationValidator
 * is a pure PHP class with no Drupal service dependencies.
 */
#[CoversClass(ApplicationValidator::class)]
#[Group('employment_application')]
class ApplicationValidatorTest extends TestCase {

  /**
   * The validator under test.
   */
  protected ApplicationValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->validator = new ApplicationValidator();
  }

  // ---------------------------------------------------------------
  // generateApplicationId()
  // ---------------------------------------------------------------

  public function testGenerateApplicationIdFormat(): void {
    $id = $this->validator->generateApplicationId([
      'full_name' => 'Jane Doe',
      'job_title' => 'Staff Attorney',
    ]);

    // Format: "Doe, Jane - Staff Attorney (YYYY-MM-DD HH-MM-SS-RANDOM)"
    $this->assertMatchesRegularExpression(
      '/^Doe, Jane - Staff Attorney \(\d{4}-\d{2}-\d{2} \d{2}-\d{2}-\d{2}-[a-f0-9]{6}\)$/',
      $id
    );
  }

  public function testGenerateApplicationIdUniqueness(): void {
    $data = ['full_name' => 'Jane Doe', 'job_title' => 'Paralegal'];
    $id1 = $this->validator->generateApplicationId($data);
    $id2 = $this->validator->generateApplicationId($data);

    // Random suffix should differ even within the same second.
    $this->assertNotEquals($id1, $id2);
  }

  public function testGenerateApplicationIdMaxLength(): void {
    $id = $this->validator->generateApplicationId([
      'full_name' => 'Jane Doe',
      'job_title' => str_repeat('A', 300),
    ]);

    $this->assertLessThanOrEqual(191, strlen($id));
  }

  public function testGenerateApplicationIdMissingName(): void {
    $id = $this->validator->generateApplicationId([
      'job_title' => 'Paralegal',
    ]);

    // Missing full_name defaults to "Unknown".
    $this->assertStringStartsWith('Unknown, Unknown - Paralegal', $id);
  }

  public function testGenerateApplicationIdSingleName(): void {
    $id = $this->validator->generateApplicationId([
      'full_name' => 'Madonna',
      'job_title' => 'Outreach Coordinator',
    ]);

    // Single-word name: last name defaults to "Unknown".
    $this->assertStringStartsWith('Unknown, Madonna - Outreach Coordinator', $id);
  }

  public function testGenerateApplicationIdMultiPartName(): void {
    $id = $this->validator->generateApplicationId([
      'full_name' => 'Mary Jane Watson-Parker',
      'job_title' => 'Legal Assistant',
    ]);

    // First word = first name, last word = last name.
    $this->assertStringStartsWith('Watson-Parker, Mary - Legal Assistant', $id);
  }

  public function testGenerateApplicationIdPreservesRandomSuffixOnTruncation(): void {
    $longTitle = str_repeat('X', 300);
    $id = $this->validator->generateApplicationId([
      'full_name' => 'Jane Doe',
      'job_title' => $longTitle,
    ]);

    // The random 6-hex-char suffix + closing paren should be preserved.
    $this->assertMatchesRegularExpression('/[a-f0-9]{6}\)$/', $id);
  }

  // ---------------------------------------------------------------
  // validateEmail()
  // ---------------------------------------------------------------

  #[DataProvider('validEmailProvider')]
  public function testValidateEmailAcceptsValid(string $email): void {
    $this->assertTrue($this->validator->validateEmail($email));
  }

  #[DataProvider('invalidEmailProvider')]
  public function testValidateEmailRejectsInvalid(string $email): void {
    $this->assertFalse($this->validator->validateEmail($email));
  }

  public static function validEmailProvider(): array {
    return [
      'simple' => ['user@example.com'],
      'subdomain' => ['user@mail.example.com'],
      'plus addressing' => ['user+tag@example.com'],
      'dots' => ['first.last@example.com'],
    ];
  }

  public static function invalidEmailProvider(): array {
    return [
      'no at sign' => ['userexample.com'],
      'no domain' => ['user@'],
      'no local part' => ['@example.com'],
      'spaces' => ['user @example.com'],
      'empty' => [''],
      'double at' => ['user@@example.com'],
    ];
  }

  // ---------------------------------------------------------------
  // validatePhone()
  // ---------------------------------------------------------------

  #[DataProvider('validPhoneProvider')]
  public function testValidatePhoneAcceptsValid(string $phone): void {
    $this->assertTrue($this->validator->validatePhone($phone));
  }

  #[DataProvider('invalidPhoneProvider')]
  public function testValidatePhoneRejectsInvalid(string $phone): void {
    $this->assertFalse($this->validator->validatePhone($phone));
  }

  public static function validPhoneProvider(): array {
    return [
      '7 digits bare' => ['1234567'],
      '10 digit US' => ['(208) 555-1234'],
      'dashes' => ['208-555-1234'],
      'dots' => ['208.555.1234'],
      'international' => ['+1-208-555-1234'],
      '15 digits max E.164' => ['123456789012345'],
    ];
  }

  public static function invalidPhoneProvider(): array {
    return [
      'too few digits' => ['123456'],
      'no digits at all' => ['no-phone'],
      'empty' => [''],
      '16 digits exceeds E.164' => ['1234567890123456'],
      'only formatting' => ['(---) --- ----'],
    ];
  }

  // ---------------------------------------------------------------
  // validateUuid()
  // ---------------------------------------------------------------

  public function testValidateUuidAcceptsValid(): void {
    $this->assertTrue(
      $this->validator->validateUuid('550e8400-e29b-41d4-a716-446655440000')
    );
  }

  public function testValidateUuidAcceptsUppercase(): void {
    $this->assertTrue(
      $this->validator->validateUuid('550E8400-E29B-41D4-A716-446655440000')
    );
  }

  #[DataProvider('invalidUuidProvider')]
  public function testValidateUuidRejectsInvalid(string $uuid): void {
    $this->assertFalse($this->validator->validateUuid($uuid));
  }

  public static function invalidUuidProvider(): array {
    return [
      'too short' => ['550e8400-e29b-41d4-a716'],
      'no hyphens' => ['550e8400e29b41d4a716446655440000'],
      'wrong segment' => ['550e8400-e29b-41d4-a716-44665544000z'],
      'empty' => [''],
      'random string' => ['not-a-uuid-at-all'],
    ];
  }

  // ---------------------------------------------------------------
  // validateFile()
  // ---------------------------------------------------------------

  public function testValidateFileAcceptsPdf(): void {
    $result = $this->validator->validateFile('pdf', 'application/pdf', 1024);
    $this->assertTrue($result['valid']);
    $this->assertNull($result['error']);
  }

  public function testValidateFileAcceptsDocx(): void {
    $result = $this->validator->validateFile(
      'docx',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      2048
    );
    $this->assertTrue($result['valid']);
  }

  public function testValidateFileAcceptsDoc(): void {
    $result = $this->validator->validateFile('doc', 'application/msword', 1024);
    $this->assertTrue($result['valid']);
  }

  public function testValidateFileRejectsOversized(): void {
    $result = $this->validator->validateFile(
      'pdf',
      'application/pdf',
      ApplicationValidator::MAX_FILE_SIZE + 1
    );
    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('5MB', $result['error']);
  }

  public function testValidateFileAcceptsExactMaxSize(): void {
    $result = $this->validator->validateFile(
      'pdf',
      'application/pdf',
      ApplicationValidator::MAX_FILE_SIZE
    );
    $this->assertTrue($result['valid']);
  }

  public function testValidateFileRejectsForbiddenExtension(): void {
    $result = $this->validator->validateFile('exe', 'application/octet-stream', 1024);
    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('PDF, DOC, and DOCX', $result['error']);
  }

  public function testValidateFileRejectsMismatchedMime(): void {
    // pdf extension but msword MIME.
    $result = $this->validator->validateFile('pdf', 'application/msword', 1024);
    $this->assertFalse($result['valid']);
    $this->assertStringContainsString('Invalid file type', $result['error']);
  }

  public function testValidateFileOctetStreamWithMagicBytes(): void {
    // Create a temp file with PDF magic bytes.
    $tmpFile = tempnam(sys_get_temp_dir(), 'test_pdf_');
    file_put_contents($tmpFile, '%PDF-1.4 fake pdf content');

    try {
      $result = $this->validator->validateFile(
        'pdf',
        'application/octet-stream',
        1024,
        $tmpFile
      );
      $this->assertTrue($result['valid']);
    }
    finally {
      @unlink($tmpFile);
    }
  }

  public function testValidateFileOctetStreamWrongMagicBytes(): void {
    // Create a temp file with wrong magic bytes for PDF.
    $tmpFile = tempnam(sys_get_temp_dir(), 'test_bad_');
    file_put_contents($tmpFile, 'This is not a PDF');

    try {
      $result = $this->validator->validateFile(
        'pdf',
        'application/octet-stream',
        1024,
        $tmpFile
      );
      $this->assertFalse($result['valid']);
      $this->assertStringContainsString('does not match', $result['error']);
    }
    finally {
      @unlink($tmpFile);
    }
  }

  // ---------------------------------------------------------------
  // verifyMagicBytes()
  // ---------------------------------------------------------------

  public function testVerifyMagicBytesPdf(): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'magic_');
    file_put_contents($tmpFile, '%PDF-1.7');

    try {
      $this->assertTrue($this->validator->verifyMagicBytes('pdf', $tmpFile));
    }
    finally {
      @unlink($tmpFile);
    }
  }

  public function testVerifyMagicBytesDocx(): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'magic_');
    file_put_contents($tmpFile, "PK\x03\x04" . 'zip content');

    try {
      $this->assertTrue($this->validator->verifyMagicBytes('docx', $tmpFile));
    }
    finally {
      @unlink($tmpFile);
    }
  }

  public function testVerifyMagicBytesDoc(): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'magic_');
    file_put_contents($tmpFile, "\xD0\xCF\x11\xE0" . 'ole content');

    try {
      $this->assertTrue($this->validator->verifyMagicBytes('doc', $tmpFile));
    }
    finally {
      @unlink($tmpFile);
    }
  }

  public function testVerifyMagicBytesMismatch(): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'magic_');
    file_put_contents($tmpFile, 'JFIF not a PDF');

    try {
      $this->assertFalse($this->validator->verifyMagicBytes('pdf', $tmpFile));
    }
    finally {
      @unlink($tmpFile);
    }
  }

  public function testVerifyMagicBytesNonexistentFile(): void {
    $this->assertFalse(
      $this->validator->verifyMagicBytes('pdf', '/nonexistent/file.pdf')
    );
  }

  public function testVerifyMagicBytesEmptyFile(): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'magic_');
    file_put_contents($tmpFile, '');

    try {
      $this->assertFalse($this->validator->verifyMagicBytes('pdf', $tmpFile));
    }
    finally {
      @unlink($tmpFile);
    }
  }

  public function testVerifyMagicBytesUnknownExtensionAllows(): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'magic_');
    file_put_contents($tmpFile, 'whatever');

    try {
      // Unknown extension has no magic bytes defined — should allow.
      $this->assertTrue($this->validator->verifyMagicBytes('txt', $tmpFile));
    }
    finally {
      @unlink($tmpFile);
    }
  }

  // ---------------------------------------------------------------
  // sanitizeInput()
  // ---------------------------------------------------------------

  public function testSanitizeInputStripsHtmlTags(): void {
    // strip_tags() removes tags but keeps inner text content.
    $this->assertSame(
      'Hello World',
      $this->validator->sanitizeInput('<b>Hello</b> World')
    );
  }

  public function testSanitizeInputStripsScriptTags(): void {
    // strip_tags() removes <script> tags; inner text is preserved by PHP.
    $result = $this->validator->sanitizeInput('<script>alert("xss")</script>Safe');
    $this->assertStringNotContainsString('<script>', $result);
    $this->assertStringContainsString('Safe', $result);
  }

  public function testSanitizeInputTrimsWhitespace(): void {
    $this->assertSame('Hello', $this->validator->sanitizeInput('  Hello  '));
  }

  public function testSanitizeInputHandlesArray(): void {
    $result = $this->validator->sanitizeInput([
      'name' => '  <b>Jane</b>  ',
      'email' => ' jane@example.com ',
    ]);

    $this->assertSame('Jane', $result['name']);
    $this->assertSame('jane@example.com', $result['email']);
  }

  public function testSanitizeInputHandlesNestedArray(): void {
    $result = $this->validator->sanitizeInput([
      'level1' => [
        'level2' => '<img src=x onerror=alert(1)>safe',
      ],
    ]);

    $this->assertSame('safe', $result['level1']['level2']);
  }

  public function testSanitizeInputHandlesNumeric(): void {
    $this->assertSame('42', $this->validator->sanitizeInput(42));
  }

  public function testSanitizeInputHandlesNull(): void {
    $this->assertSame('', $this->validator->sanitizeInput(NULL));
  }

  // ---------------------------------------------------------------
  // generateSecureFilename()
  // ---------------------------------------------------------------

  public function testGenerateSecureFilenameFormat(): void {
    $filename = $this->validator->generateSecureFilename('My Resume (2024).pdf');

    // Should match: sanitized_basename_timestamp_random.ext
    $this->assertMatchesRegularExpression(
      '/^My_Resume__2024__\d+_[a-f0-9]{8}\.pdf$/',
      $filename
    );
  }

  public function testGenerateSecureFilenameStripsSpecialChars(): void {
    $filename = $this->validator->generateSecureFilename('file<script>.docx');

    // Script tags and angle brackets should be replaced with underscores.
    $this->assertStringNotContainsString('<', $filename);
    $this->assertStringNotContainsString('>', $filename);
    $this->assertStringEndsWith('.docx', $filename);
  }

  public function testGenerateSecureFilenameLowercasesExtension(): void {
    $filename = $this->validator->generateSecureFilename('Resume.PDF');
    $this->assertStringEndsWith('.pdf', $filename);
  }

  public function testGenerateSecureFilenameUniqueness(): void {
    $f1 = $this->validator->generateSecureFilename('resume.pdf');
    $f2 = $this->validator->generateSecureFilename('resume.pdf');

    // Random suffix should differ.
    $this->assertNotEquals($f1, $f2);
  }

  // ---------------------------------------------------------------
  // formatPositionTitle()
  // ---------------------------------------------------------------

  public function testFormatPositionTitleKnownPositions(): void {
    $this->assertSame('Managing Attorney', $this->validator->formatPositionTitle('managing_attorney'));
    $this->assertSame('Staff Attorney', $this->validator->formatPositionTitle('staff_attorney'));
    $this->assertSame('Paralegal', $this->validator->formatPositionTitle('paralegal'));
    $this->assertSame('Legal Assistant', $this->validator->formatPositionTitle('legal_assistant'));
    $this->assertSame('Administrative Support', $this->validator->formatPositionTitle('administrative'));
    $this->assertSame('Community Outreach', $this->validator->formatPositionTitle('outreach'));
  }

  public function testFormatPositionTitleUnknownPosition(): void {
    $this->assertSame(
      'Some New Role',
      $this->validator->formatPositionTitle('some_new_role')
    );
  }

  // ---------------------------------------------------------------
  // formatYesNoOption()
  // ---------------------------------------------------------------

  public function testFormatYesNoOptionKnownValues(): void {
    $this->assertSame('Yes', $this->validator->formatYesNoOption('yes'));
    $this->assertSame('Yes', $this->validator->formatYesNoOption('YES'));
    $this->assertSame('No', $this->validator->formatYesNoOption('no'));
    $this->assertSame('Licensed in Another State', $this->validator->formatYesNoOption('licensed_other_state'));
  }

  public function testFormatYesNoOptionFallback(): void {
    $this->assertSame('Some custom value', $this->validator->formatYesNoOption('some_custom_value'));
  }

  // ---------------------------------------------------------------
  // hashIp()
  // ---------------------------------------------------------------

  public function testHashIpReturns64CharHex(): void {
    $hash = $this->validator->hashIp('192.168.1.1', 'test-salt');
    $this->assertSame(64, strlen($hash));
    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
  }

  public function testHashIpDeterministic(): void {
    // Same inputs on same day should produce same hash.
    $hash1 = $this->validator->hashIp('10.0.0.1', 'salt123');
    $hash2 = $this->validator->hashIp('10.0.0.1', 'salt123');
    $this->assertSame($hash1, $hash2);
  }

  public function testHashIpDifferentIpsDifferentHashes(): void {
    $hash1 = $this->validator->hashIp('10.0.0.1', 'salt');
    $hash2 = $this->validator->hashIp('10.0.0.2', 'salt');
    $this->assertNotEquals($hash1, $hash2);
  }

  public function testHashIpDifferentSaltsDifferentHashes(): void {
    $hash1 = $this->validator->hashIp('10.0.0.1', 'salt-a');
    $hash2 = $this->validator->hashIp('10.0.0.1', 'salt-b');
    $this->assertNotEquals($hash1, $hash2);
  }

  // ---------------------------------------------------------------
  // Constants sanity checks
  // ---------------------------------------------------------------

  public function testConstantsAreCorrect(): void {
    $this->assertSame(5242880, ApplicationValidator::MAX_FILE_SIZE);
    $this->assertSame(['pdf', 'doc', 'docx'], ApplicationValidator::ALLOWED_EXTENSIONS);
    $this->assertSame(3, ApplicationValidator::MIN_FORM_TIME_SECONDS);
    $this->assertSame(7, ApplicationValidator::MIN_PHONE_DIGITS);
    $this->assertSame(15, ApplicationValidator::MAX_PHONE_DIGITS);
  }

  public function testAllExtensionsHaveMimeTypes(): void {
    foreach (ApplicationValidator::ALLOWED_EXTENSIONS as $ext) {
      $this->assertArrayHasKey(
        $ext,
        ApplicationValidator::ALLOWED_MIME_TYPES,
        "Extension '{$ext}' has no MIME types defined."
      );
    }
  }

  public function testAllExtensionsAllowOctetStream(): void {
    foreach (ApplicationValidator::ALLOWED_MIME_TYPES as $ext => $mimes) {
      $this->assertContains(
        'application/octet-stream',
        $mimes,
        "Extension '{$ext}' is missing the octet-stream fallback."
      );
    }
  }

}
