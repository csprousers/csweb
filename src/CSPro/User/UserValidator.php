<?php

namespace App\CSPro\User;

use App\CSPro\User\User;
use Psr\Log\LoggerInterface;

class UserValidator {

    private array $errors = [];

    // Field length constraints
    private const MIN_USERNAME_LENGTH = 4;
    private const MAX_USERNAME_LENGTH = 64;
    private const MIN_PASSWORD_LENGTH = 8;
    private const MAX_PASSWORD_LENGTH = 128;
    private const MAX_NAME_LENGTH     = 100;
    private const MAX_EMAIL_LENGTH    = 254; // RFC 5321
    private const MAX_PHONE_LENGTH    = 30;

    public function __construct(private $rolesMap, private LoggerInterface $logger) {}

    public function isValid(): bool {
        return count($this->errors) === 0;
    }

    public function getErrors(): array {
        return $this->errors;
    }

    public function reset(): void {
        $this->errors = [];
    }

    public function addError(string $errMsg): void {
        $this->errors[] = $errMsg;
    }

    // -------------------------------------------------------------------------
    // Sanitization helpers
    // -------------------------------------------------------------------------

    /**
     * Strip tags and trim. Returns null for empty/whitespace-only strings.
     */
    private function sanitizeText(?string $value): ?string {
        if ($value === null) {
            return null;
        }
        $value = trim(strip_tags($value));
        return $value === '' ? null : $value;
    }

    /**
     * Strip anything from a phone string that is not a digit, +, -, (, ), or space.
     */
    private function sanitizePhone(?string $value): ?string {
        $value = $this->sanitizeText($value);
        if ($value === null) {
            return null;
        }
        $value = preg_replace('/[^\d\+\-\(\)\s]/', '', $value);
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    /**
     * Sanitize all string fields on a User in place.
     * Called by both validateUser() and validate() (import path).
     * Passwords are intentionally NOT trimmed — spaces are valid password chars.
     */
    private function sanitizeUser(User $user): void {
        $user->setUserName($this->sanitizeText($user->getUsername()));
        $user->setFirstName($this->sanitizeText($user->getFirstName()));
        $user->setLastName($this->sanitizeText($user->getLastName()));
        $user->setEmail($this->sanitizeText($user->getEmail()));
        $user->setPhone($this->sanitizePhone($user->getPhone()));
    }

    // -------------------------------------------------------------------------
    // Shared business-rule checks (used by both validate paths)
    // -------------------------------------------------------------------------

    /**
     * Run all business-rule checks and return a concatenated error string.
     * An empty string means no errors.
     *
     * @param bool $isUpdate  When true, an absent/empty password is allowed.
     */
    private function runRules(User $user, bool $isUpdate): string {
        $errMsg = '';

        // --- Username ---
        $username = $user->getUsername();
        if ($username === null || strlen($username) < self::MIN_USERNAME_LENGTH) {
            $errMsg .= 'Username must be at least ' . self::MIN_USERNAME_LENGTH . ' characters;';
        } elseif (strlen($username) > self::MAX_USERNAME_LENGTH) {
            $errMsg .= 'Username must not exceed ' . self::MAX_USERNAME_LENGTH . ' characters;';
        } elseif (!ctype_alnum($username)) {
            $errMsg .= 'Username must contain only letters and numbers;';
        }

        // --- First name ---
        $firstName = $user->getFirstName();
        if ($firstName === null || $firstName === '') {
            $errMsg .= 'First Name is required;';
        } elseif (strlen($firstName) > self::MAX_NAME_LENGTH) {
            $errMsg .= 'First Name must not exceed ' . self::MAX_NAME_LENGTH . ' characters;';
        } elseif (!preg_match("/^[\p{L}'\-\s]+$/u", $firstName)) {
            $errMsg .= 'Invalid First Name;';
        }

        // --- Last name ---
        $lastName = $user->getLastName();
        if ($lastName === null || $lastName === '') {
            $errMsg .= 'Last Name is required;';
        } elseif (strlen($lastName) > self::MAX_NAME_LENGTH) {
            $errMsg .= 'Last Name must not exceed ' . self::MAX_NAME_LENGTH . ' characters;';
        } elseif (!preg_match("/^[\p{L}'\-\s]+$/u", $lastName)) {
            $errMsg .= 'Invalid Last Name;';
        }

        // --- Password ---
        // On update, an absent/empty password means "do not change" — skip the check.
        $password = $user->getPassword();
        $passwordProvided = ($password !== null && $password !== '');
        if (!$isUpdate) {
            // Add: password is mandatory
            if (!$passwordProvided) {
                $errMsg .= 'Password is required;';
            } elseif (strlen($password) < self::MIN_PASSWORD_LENGTH) {
                $errMsg .= 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters;';
            } elseif (strlen($password) > self::MAX_PASSWORD_LENGTH) {
                $errMsg .= 'Password must not exceed ' . self::MAX_PASSWORD_LENGTH . ' characters;';
            }
        } elseif ($passwordProvided) {
            // Update: only validate if a new password was actually supplied
            if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
                $errMsg .= 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters;';
            } elseif (strlen($password) > self::MAX_PASSWORD_LENGTH) {
                $errMsg .= 'Password must not exceed ' . self::MAX_PASSWORD_LENGTH . ' characters;';
            }
        }

        // --- Role --- id could be a name before the transformation when importing.
        if (!in_array($user->getRoleId(), $this->rolesMap, true) && 
            !array_key_exists($user->getRoleId(), $this->rolesMap)) {
            $errMsg .= 'Invalid Role;';
        }

        // --- Email (optional) ---
        $email = $user->getEmail();
        if ($email !== null && $email !== '') {
            if (strlen($email) > self::MAX_EMAIL_LENGTH) {
                $errMsg .= 'Email must not exceed ' . self::MAX_EMAIL_LENGTH . ' characters;';
            } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errMsg .= 'Invalid email address;';
            }
        }

        // --- Phone (optional) ---
        $phone = $user->getPhone();
        if ($phone !== null && $phone !== '') {
            if (strlen($phone) > self::MAX_PHONE_LENGTH) {
                $errMsg .= 'Phone must not exceed ' . self::MAX_PHONE_LENGTH . ' characters;';
            }
        }

        return $errMsg;
    }

    // -------------------------------------------------------------------------
    // API / UI path
    // -------------------------------------------------------------------------

    /**
     * Validate a single User coming from a JSON API or UI form.
     *
     * Sanitizes the User in place before running rules so the object is
     * always clean regardless of validation outcome.
     *
     * @param  User $user
     * @param  bool $isUpdate  Pass true for PUT/update — relaxes password rule.
     * @return bool            True if valid.
     */
    public function validateUser(User $user, bool $isUpdate = false): bool {
        $this->reset();
        $this->sanitizeUser($user);

        $errMsg = $this->runRules($user, $isUpdate);

        if (!empty($errMsg)) {
            $this->addError('Error: ' . $errMsg);
        }

        return $this->isValid();
    }

    // -------------------------------------------------------------------------
    // CSV import path
    // -------------------------------------------------------------------------

    /**
     * Validate a single User row from a CSV import.
     * Errors are accumulated across calls — do NOT call reset() between rows.
     *
     * @param  User     $user
     * @param  int|null $lineNumber  1-based line number for error context.
     * @return bool
     */
    private function validate(User $user, ?int $lineNumber = null): bool {
        $this->sanitizeUser($user);

        // Import always treats records as "add" — passwords are required.
        $errMsg = $this->runRules($user, false);

        if (!empty($errMsg)) {
            $context = isset($lineNumber)
                ? "Error: {$errMsg} Line number:{$lineNumber} User:[{$user->toString()}]"
                : "Error: {$errMsg} User:[{$user->toString()}]";
            $this->addError($context);
        }

        return empty($errMsg);
    }

    /**
     * Parse and validate a raw CSV import payload.
     *
     * Expected column order (no uuid — generated on insert):
     *   0: username
     *   1: firstName
     *   2: lastName
     *   3: roleId
     *   4: password
     *   5: email    (optional)
     *   6: phone    (optional)
     *
     * All errors across all lines are collected before returning so the
     * caller can report everything in one pass.
     *
     * @param  string $content    Raw CSV text.
     * @param  bool   $headerRow  True if the first line is a header to skip.
     * @return bool               True if every row is valid.
     */
    public function validateImportUsers(string $content, bool $headerRow): bool {
        // Remove blank lines then re-index
        $content = preg_replace('/^[ \t]*[\r\n]+/m', '', $content);
        $lines   = array_values(array_filter(explode("\n", $content), 'strlen'));

        $this->reset();

        $lineCount = count($lines);
        if ($headerRow) {
            $lineCount = $lineCount > 0 ? $lineCount - 1 : 0;
        }

        if ($lineCount === 0) {
            $this->addError('Import file is empty.');
            return false;
        }

        $this->logger->debug('validateImportUsers: ' . $lineCount . ' data rows.');

        $startLine = $headerRow ? 1 : 0;

        // Reuse a single User instance across rows to avoid allocating
        // a new object per line on large imports.
        $user = new User(null, null, null, null, null, null);

        for ($i = $startLine; $i < count($lines); $i++) {
            $csv = str_getcsv($lines[$i], ',', '"');

            // Normalise to exactly 7 slots; trim and convert empty strings to null.
            // Columns: 0=username, 1=firstName, 2=lastName, 3=roleId,
            //          4=password, 5=email (optional), 6=phone (optional)
            for ($col = 0; $col < 7; $col++) {
                if (!isset($csv[$col]) || trim($csv[$col]) === '') {
                    $csv[$col] = null;
                } else {
                    $csv[$col] = trim($csv[$col]);
                }
            }

            // Empty first column after normalisation means end of data —
            // check after normalise so a whitespace-only value is caught too.
            if ($csv[0] === null) {
                break;
            }

            $user->setAllMembers(
                null,      // uuid — generated on insert, not in CSV
                $csv[0],   // username
                $csv[1],   // firstName
                $csv[2],   // lastName
                $csv[3],   // roleId
                $csv[4],   // password
                $csv[5],   // email    (optional)
                $csv[6]    // phone    (optional)
            );

            $this->validate($user, $i + 1);
        }

        return $this->isValid();
    }
}