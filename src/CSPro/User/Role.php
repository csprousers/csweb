<?php

namespace App\CSPro\User;


use App\CSPro\User\RoleDictionaryPermissions;
use App\CSPro\User\RolePermissions;
/**
 * Description of Role
 *
 * @author savy
 */
class Role {

    public $name;
    public $id;
    public $rolePermissions;

    public const BUILTIN_ROLE_MAX_ID = 3;

    public function __construct() {
        $this->rolePermissions = new RolePermissions();
    }

    public function getName() {
        return $this->name;
    }

    public function getId() {
        return $this->id;
    }

    public function setName($name) {
        $this->name = $name;
    }

    public function setId($id) {
        $this->id = $id;
    }

    public static function fromJSON(string $json): self {
        $roleData = json_decode($json, true);
        $result = new self();
        $result->id = empty($roleData['id']) ? 0 : (int)$roleData['id'];
        $result->name = empty($roleData['name']) ? '': htmlspecialchars(strip_tags($roleData['name']), ENT_QUOTES, 'UTF-8');
       
        $result->rolePermissions->setPermission(RolePermissions::LOGIN_ALL, !empty($roleData['login']));
        self::addPermissionsFromJson($roleData, 'data', $result->rolePermissions);
        self::addPermissionsFromJson($roleData, 'dictionary', $result->rolePermissions);
        self::addPermissionsFromJson($roleData, 'apps', $result->rolePermissions);
        self::addPermissionsFromJson($roleData, 'files', $result->rolePermissions);
        self::addPermissionsFromJson($roleData, 'reports', $result->rolePermissions);
        self::addPermissionsFromJson($roleData, 'users', $result->rolePermissions);
        self::addPermissionsFromJson($roleData, 'roles', $result->rolePermissions);

        self::addDictionaryPermissionsFromJson($roleData['dictionaries'], $result->rolePermissions);
        //
        //settings, paradata and messages. not exposed. setting defaults belo2.
        if(!empty($result->rolePermissions->permissions[RolePermissions::DATA_READ])){
            //settings are allowed to be read/written when data read is allowed This is for blob breakout
            $result->rolePermissions->permissions[RolePermissions::SETTINGS_READ] = true;
            $result->rolePermissions->permissions[RolePermissions::SETTINGS_WRITE] = true;
            //paradata read is allowed if data read is allowed.
            $result->rolePermissions->permissions[RolePermissions::PARADATA_READ] = true;
            $result->rolePermissions->permissions[RolePermissions::MESSAGES_READ] = true;
        }
        if(!empty($result->rolePermissions->permissions[RolePermissions::DATA_WRITE])){
             //paradata write is allowed if data write is allowed.
            $result->rolePermissions->permissions[RolePermissions::PARADATA_WRITE] = true;
            $result->rolePermissions->permissions[RolePermissions::MESSAGES_WRITE] = true;
        }
        return $result;
    }

    public function toJSON(): string {
        $result = [
            'id' => $this->id,
            'name' => $this->name,
            'data' => [],
            'dictionary' => [],
            'apps' => [],
            'files' => [],
            'reports' => [],
            'users' => [],
            'roles' => [],
            'login' => !empty($this->rolePermissions->getPermission(RolePermissions::LOGIN_ALL)),
            'dictionaries' => []
        ];

        $permissions = $this->rolePermissions->getPermissionsForWrite();
        foreach ($permissions as $permType => $flag) {
            if (isset(RolePermissions::PERMISSION_STRING_MAP[$permType])) {
                $entry = RolePermissions::PERMISSION_STRING_MAP[$permType];
                $result[$entry['key']][] = $entry['value'];
            }
        }
        $this->addDictionaryPermissions($result);
        return json_encode($result);
    }

    public static function addPermissionsFromJson(array $rolePermissionsData, string $key, RolePermissions $rolePermissions): void {
        $permissions = $rolePermissionsData[$key];
        foreach ($permissions ?? [] as $value) {
            if (isset(RolePermissions::STRING_PERMISSION_MAP[$value])) {
                $permType = RolePermissions::STRING_PERMISSION_MAP[$value];
                $rolePermissions->setPermission($permType, true);
            }
        }
    }

    public static function addDictionaryPermissionsFromJson(array $rolePermissionsData, RolePermissions $rolePermissions): void {
        //for each key of dictionary permissions, create the Dictionary roles permissions and add it to role permissions
        foreach ($rolePermissionsData as $key => $value) {
            if ($value) {
                $dictionaryPermission = RoleDictionaryPermissions::fromJSON($value, $rolePermissions->permissions);
                $rolePermissions->setDictionaryPermission($dictionaryPermission);
            }
        }
    }

    private function addDictionaryPermissions(array &$result): void {
        $key = 'dictionaries';
        $result[$key] = [];
        foreach ($this->rolePermissions->dictionaryPermissions as $dictName => $permission) {
            $dictionaryPermission = [
                'id' => $permission->dictionaryId,
                'name' => $permission->dictionaryName,
                'label' => $permission->dictionaryLabel,
            ];

            if (!$permission->useDefault) {
                $dictionaryPermission['permissions'] = [];
                $effectivePermissions = $permission->getPermissionsForWrite();

                foreach ($effectivePermissions as $permKey => $permType) {
                    $entry = RolePermissions::PERMISSION_STRING_MAP[$permKey];
                    $dictionaryPermission['permissions'][] = $entry['value'];
                }
            }

            $result[$key][$dictName] = $dictionaryPermission;
        }
    }
}
