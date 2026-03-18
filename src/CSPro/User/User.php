<?php

namespace App\CSPro\User;

use App\CSPro\User\Role;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * User domain object.
 *
 * fromArray() is the single entry point for constructing a User from
 * external input (API/UI). It validates the shape of the data and throws
 * \InvalidArgumentException on structural problems. Business-rule validation
 * (min length, format, etc.) is the responsibility of UserValidator.
 */
class User implements UserInterface {

    private $roles;
    private $userRole;

    public const STANDARD_USER = 1;
    public const ADMINISTRATOR = 2;
    public const DEVELOPER     = 3;

    // Keys that must be present and non-array in every inbound payload.
    // Password is intentionally absent for updates its optional  from the UI 
    // (required for add, optional for update) handled by UserValidator.
    private const REQUIRED_KEYS = ['username', 'firstName', 'lastName', 'role'];

    function __construct(
        private $uuid,
        private $userName,
        private $firstName,
        private $lastName,
        private $roleId,
        private $password,
        private $email = null,
        private $phone = null
    ) {
        $this->roles = [];
    }

    /**
     * Construct a User from a decoded JSON payload (associative array).
     *
     * Throws \InvalidArgumentException if:
     *   - A required key is absent from the array
     *   - Any value is the wrong type (e.g. an array instead of a scalar)
     *
     * Does NOT throw on empty strings or business-rule violations 
     * those are UserValidator's responsibility.
     *
     * @param  array $params  Associative array from json_decode(..., true)
     * @return static
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $params): static {
        // 1. Check required keys are present
        foreach (self::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $params)) {
                throw new \InvalidArgumentException(
                    "Missing required field: '$key'."
                );
            }
        }

        // 2. Check all present values are scalar (not arrays or objects)
        $allKeys = array_merge(self::REQUIRED_KEYS, ['password', 'email', 'phone']);
        foreach ($allKeys as $key) {
            if (array_key_exists($key, $params) && $params[$key] !== null && !is_scalar($params[$key])) {
                throw new \InvalidArgumentException(
                    "Field '$key' must be a scalar value, " . gettype($params[$key]) . " given."
                );
            }
        }

        // 3. Structural normalisation  username is always lowercase.
        //    This is not a business rule; it is an invariant of the domain.
        $username = is_string($params['username']) ? strtolower($params['username']) : $params['username'];

        return new static(
            null,                            // uuid  assigned on insert
            $username,
            $params['firstName'],
            $params['lastName'],
            $params['role'],
            $params['password'] ?? null,     // optional at this layer
            $params['email']    ?? null,
            $params['phone']    ?? null
        );
    }

    public function setAllMembers($uuid, $username, $firstname, $lastname, $roleId, $password, $email = null, $phone = null): void {
        $this->uuid      = $uuid;
        $this->userName  = $username;
        $this->firstName = $firstname;
        $this->lastName  = $lastname;
        $this->roleId    = $roleId;
        $this->password  = $password;
        $this->email     = $email;
        $this->phone     = $phone;
    }

    public function getUserIdentifier(): string {
        return $this->getUsername();
    }

    public function getUuid() { return $this->uuid; }

    public function getUsername() { return $this->userName; }
    public function setUserName($userName): void { $this->userName = $userName; }

    public function getPassword() { return $this->password; }
    public function setPassword($password): void { $this->password = $password; }

    public function getFirstName() { return $this->firstName; }
    public function setFirstName($firstName): void { $this->firstName = $firstName; }

    public function getLastName() { return $this->lastName; }
    public function setLastName($lastName): void { $this->lastName = $lastName; }

    public function getEmail() { return $this->email; }
    public function setEmail($email): void { $this->email = $email; }

    public function getPhone() { return $this->phone; }
    public function setPhone($phone): void { $this->phone = $phone; }

    public function getRoles(): array { return $this->roles; }
    public function setRoles($roles): void { $this->roles = $roles; }

    public function getUserRole(): Role { return $this->userRole; }
    public function setUserRole(Role $role): void { $this->userRole = $role; }

    public function eraseCredentials(): void {}

    public function getRoleId() { return $this->roleId; }
    public function setRoleId($roleId): void { $this->roleId = $roleId; }

    public function toString(): string {
        $uuid      = $this->uuid      ?? 'NA';
        $userName  = $this->userName  ?? 'NA';
        $firstName = $this->firstName ?? 'NA';
        $lastName  = $this->lastName  ?? 'NA';
        $roleId    = $this->roleId    ?? 'NA';
        return "uuid: $uuid username: $userName firstname: $firstName lastname: $lastName role: $roleId";
    }
}
