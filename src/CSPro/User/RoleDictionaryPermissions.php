<?php
namespace App\CSPro\User;

class RoleDictionaryPermissions {

    public function __construct(
            public string $dictionaryName = '',
            public string $dictionaryId = '',
            public string $dictionaryLabel = '',
            public bool $useDefault = false,
            public ?array $permissions = null,
            public ?array $defaultPermissions = null
    ) {

    }

    public static function fromJSON(array $dictionaryPermissions, array $defaultPermissions): self {
        $dictionaryName = $dictionaryPermissions['name'];
        $dictionaryId = $dictionaryPermissions['id'];
        $dictionaryLabel = $dictionaryPermissions['label'];
        $useDefault = empty($dictionaryPermissions['permissions']) ? true : false;
        $permissions = [];
        if (!$useDefault) {
            $permissionsArray = $dictionaryPermissions['permissions'] ?? [];
            foreach ($permissionsArray as $permission) {
                $permType = RolePermissions::STRING_PERMISSION_MAP[$permission];
                $permissions[$permType] = true;
            }
        }
        $result = new self($dictionaryName, $dictionaryId, $dictionaryLabel, $useDefault, $permissions, $defaultPermissions);
        return $result;
    }

    public function setUseDefault(bool $flag): void {
        $this->useDefault = $flag;
    }

    public function setDefaultPermissions(array $defaultPermissions): void {
        $this->defaultPermissions = $defaultPermissions;
    }

    public function canReadData(): bool {
        if ($this->useDefault && !empty($this->defaultPermissions)) {
            return !empty($this->defaultPermissions[RolePermissions::DATA_READ]) ||  !empty($this->defaultPermissions[RolePermissions::DATA_ALL]);
        }
        return !empty($this->permissions[RolePermissions::DATA_READ]) ||  !empty($this->permissions[RolePermissions::DATA_ALL]);
    }

    public function canWriteData(): bool {
        if ($this->useDefault && !empty($this->defaultPermissions)) {
            return !empty($this->defaultPermissions[RolePermissions::DATA_WRITE]) ||  !empty($this->defaultPermissions[RolePermissions::DATA_ALL]);
        }
        return !empty($this->permissions[RolePermissions::DATA_WRITE]) || !empty($this->permissions[RolePermissions::DATA_ALL]);
    }

    public function canAPIClearData(): bool {
        if ($this->useDefault && !empty($this->defaultPermissions)) {
            return !empty($this->defaultPermissions[RolePermissions::DATA_CLEAR]) ||  !empty($this->defaultPermissions[RolePermissions::DATA_ALL]);
        }
        return !empty($this->permissions[RolePermissions::DATA_CLEAR]) || !empty($this->permissions[RolePermissions::DATA_ALL]);
    }

    public function canClearDashboard(): bool {
        if ($this->useDefault && !empty($this->defaultPermissions)) {
            return !empty($this->defaultPermissions[RolePermissions::DATA_CLEAR_DASHBOARD]) ||  !empty($this->defaultPermissions[RolePermissions::DATA_ALL]);
        }
        return !empty($this->permissions[RolePermissions::DATA_CLEAR_DASHBOARD])|| !empty($this->permissions[RolePermissions::DATA_ALL]);
    }

    public function getPermissionsForWrite(): ?array{
        if ($this->useDefault) {
            return null;
        }
        $result = $this->permissions;
        //if DATA_ALL is set just remove all the other permission settings if any
        if (!empty($result[RolePermissions::DATA_ALL])) {
            foreach (RolePermissions::GROUPS[RolePermissions::DATA_ALL] as $perm) {
                unset($result[$perm]);
            }
        }
        return $result;
    }

    //evaluates dictionary data effective permissions based on defaults.
    public function getEffectivePermissions(): array {
        $permissionsArray = $this->useDefault ? $this->defaultPermissions :  $this->permissions;
        //if DATA_ALL is set just remove all the other permission settings if any
        if (!empty($permissionsArray[RolePermissions::DATA_ALL])) {
            foreach (RolePermissions::GROUPS[RolePermissions::DATA_ALL] as $perm) {
                unset($permissionsArray[$perm]);
            }
        }
        $result  = [];
        $dataPermissions = RolePermissions::GROUPS[RolePermissions::DATA_ALL];
        array_push($dataPermissions, RolePermissions::DATA_ALL);
        foreach ($permissionsArray as $permKey => $hasPermission) {
            if (isset(RolePermissions::PERMISSION_STRING_MAP[$permKey]) && $hasPermission && in_array($permKey, $dataPermissions) ) {
                $entry = RolePermissions::PERMISSION_STRING_MAP[$permKey] ?? null;
                if(isset($entry['value'])){
                    $result[] = $entry['value'];
                }
            }
        }
        return $result;
    }
}