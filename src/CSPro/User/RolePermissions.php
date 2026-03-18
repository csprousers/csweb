<?php

namespace App\CSPro\User;

use App\Security\DictionaryVoter;
use App\Security\AppsVoter;
use App\Security\FilesVoter;
use App\Security\UserVoter;
use App\Security\ReportsVoter;
use App\Security\ParadataVoter;
use App\Security\SettingsVoter;
use App\Security\MessagesVoter;
use App\Security\RolesVoter;

class RolePermissions {

    public const DATA_ALL = 1;
    public const APPS_ALL = 10;
    public const USERS_ALL = 20;
    public const ROLES_ALL = 30;
    public const REPORTS_ALL = 40;
    public const SETTINGS_ALL = 50;
    public const PARADATA_ALL = 60;
    public const DICTIONARIES_ALL = 70;
    public const MESSAGES_ALL = 80;
    public const FILES_ALL = 90;
    public const LOGIN_ALL = 100;
    public const REPORTS_READ = 41;
    public const REPORTS_WRITE = 42;
    public const SETTINGS_READ = 51;
    public const SETTINGS_WRITE = 52;
    public const PARADATA_READ = 61;
    public const PARADATA_WRITE = 62;
    public const DICTIONARIES_READ = 71;
    public const DICTIONARIES_WRITE = 72;
    public const DATA_READ = 2;
    public const DATA_WRITE = 3;
    public const DATA_CLEAR = 4;
    public const DATA_CLEAR_DASHBOARD = 5;
    public const DATA_NONE = 6;
    public const APPS_READ = 11;
    public const APPS_WRITE = 12;
    public const USERS_READ = 21;
    public const USERS_WRITE = 22;
    public const ROLES_READ = 31;
    public const ROLES_WRITE = 32;
    public const MESSAGES_READ = 81;
    public const MESSAGES_WRITE = 82;
    public const FILES_READ = 91;
    public const FILES_WRITE = 92;

    public $permissions;
    public $dictionaryPermissions;

    public const GROUPS = [
        self::DATA_ALL => [
            self::DATA_READ,
            self::DATA_WRITE,
            self::DATA_CLEAR,
            self::DATA_CLEAR_DASHBOARD,
            self::DATA_NONE,
        ],
        self::APPS_ALL => [
            self::APPS_READ,
            self::APPS_WRITE,
        ],
        self::USERS_ALL => [
            self::USERS_READ,
            self::USERS_WRITE,
        ],
        self::ROLES_ALL => [
            self::ROLES_READ,
            self::ROLES_WRITE,
        ],
        self::REPORTS_ALL => [
            self::REPORTS_READ,
            self::REPORTS_WRITE,
        ],
        self::SETTINGS_ALL => [
            self::SETTINGS_READ,
            self::SETTINGS_WRITE,
        ],
        self::PARADATA_ALL => [
            self::PARADATA_READ,
            self::PARADATA_WRITE,
        ],
        self::DICTIONARIES_ALL => [
            self::DICTIONARIES_READ,
            self::DICTIONARIES_WRITE,
        ],
        self::LOGIN_ALL => [
        // No granular LOGIN permissions defined, but placeholder in case you add later
        ],
    ];
    public const PERMISSION_STRING_MAP = [
        self::DATA_ALL => ['key' => 'data', 'value' => DictionaryVoter::DATA_ALL],
        self::DATA_READ => ['key' => 'data', 'value' => DictionaryVoter::DATA_READ],
        self::DATA_WRITE => ['key' => 'data', 'value' => DictionaryVoter::DATA_WRITE],
        self::DATA_CLEAR => ['key' => 'data', 'value' => DictionaryVoter::DATA_CLEAR],
        self::DATA_CLEAR_DASHBOARD => ['key' => 'data', 'value' => DictionaryVoter::DATA_CLEAR_DASHBOARD],
        self::DATA_NONE => ['key' => 'data', 'value' => DictionaryVoter::DATA_NONE],
        //dictionary
        self::DICTIONARIES_ALL => ['key' => 'dictionary', 'value' => DictionaryVoter::DICTIONARIES_ALL],
        self::DICTIONARIES_READ => ['key' => 'dictionary', 'value' => DictionaryVoter::DICTIONARIES_READ],
        self::DICTIONARIES_WRITE => ['key' => 'dictionary', 'value' => DictionaryVoter::DICTIONARIES_WRITE],
        //apps
        self::APPS_ALL => ['key' => 'apps', 'value' => AppsVoter::APPS_ALL],
        self::APPS_READ => ['key' => 'apps', 'value' => AppsVoter::APPS_READ],
        self::APPS_WRITE => ['key' => 'apps', 'value' => AppsVoter::APPS_WRITE],
        //files
        self::FILES_ALL => ['key' => 'files', 'value' => FilesVoter::FILES_ALL],
        self::FILES_READ => ['key' => 'files', 'value' => FilesVoter::FILES_READ],
        self::FILES_WRITE => ['key' => 'files', 'value' => FilesVoter::FILES_WRITE],
        self::REPORTS_ALL => ['key' => 'reports', 'value' => ReportsVoter::REPORTS_ALL],
        self::REPORTS_READ => ['key' => 'reports', 'value' => ReportsVoter::REPORTS_READ],
        self::REPORTS_WRITE => ['key' => 'reports', 'value' => ReportsVoter::REPORTS_WRITE],
        // Users permissions
        self::USERS_ALL => ['key' => 'users', 'value' => UserVoter::USERS_ALL],
        self::USERS_READ => ['key' => 'users', 'value' => UserVoter::USERS_READ],
        self::USERS_WRITE => ['key' => 'users', 'value' => UserVoter::USERS_WRITE],
        // Roles permissions
        self::ROLES_ALL => ['key' => 'roles', 'value' => RolesVoter::ROLES_ALL],
        self::ROLES_READ => ['key' => 'roles', 'value' => RolesVoter::ROLES_READ],
        self::ROLES_WRITE => ['key' => 'roles', 'value' => RolesVoter::ROLES_WRITE],
    ];
    public const STRING_PERMISSION_MAP = [
        // Apps permissions
        AppsVoter::APPS_ALL => self::APPS_ALL,
        AppsVoter::APPS_READ => self::APPS_READ,
        AppsVoter::APPS_WRITE => self::APPS_WRITE,
        // Dictionary data permissions
        DictionaryVoter::DATA_ALL => self::DATA_ALL,
        DictionaryVoter::DATA_CLEAR => self::DATA_CLEAR,
        DictionaryVoter::DATA_CLEAR_DASHBOARD => self::DATA_CLEAR_DASHBOARD,
        DictionaryVoter::DATA_READ => self::DATA_READ,
        DictionaryVoter::DATA_WRITE => self::DATA_WRITE,
        DictionaryVoter::DATA_NONE => self::DATA_NONE,
        // Dictionary  permissions
        DictionaryVoter::DICTIONARIES_ALL => self::DICTIONARIES_ALL,
        DictionaryVoter::DICTIONARIES_READ => self::DICTIONARIES_READ,
        DictionaryVoter::DICTIONARIES_WRITE => self::DICTIONARIES_WRITE,
        // Files permissions
        FilesVoter::FILES_ALL => self::FILES_ALL,
        FilesVoter::FILES_READ => self::FILES_READ,
        FilesVoter::FILES_WRITE => self::FILES_WRITE,
        // Messages permissions
        MessagesVoter::MESSAGES_ALL => self::MESSAGES_ALL,
        MessagesVoter::MESSAGES_READ => self::MESSAGES_READ,
        MessagesVoter::MESSAGES_WRITE => self::MESSAGES_WRITE,
        // Paradata permissions
        ParadataVoter::PARADATA_ALL => self::PARADATA_ALL,
        ParadataVoter::PARADATA_READ => self::PARADATA_READ,
        ParadataVoter::PARADATA_WRITE => self::PARADATA_WRITE,
        // Reports permissions
        ReportsVoter::REPORTS_ALL => self::REPORTS_ALL,
        ReportsVoter::REPORTS_READ => self::REPORTS_READ,
        ReportsVoter::REPORTS_WRITE => self::REPORTS_WRITE,
        // Roles permissions
        RolesVoter::ROLES_ALL => self::ROLES_ALL,
        RolesVoter::ROLES_READ => self::ROLES_READ,
        RolesVoter::ROLES_WRITE => self::ROLES_WRITE,
        // Settings permissions
        SettingsVoter::SETTINGS_ALL => self::SETTINGS_ALL,
        SettingsVoter::SETTINGS_READ => self::SETTINGS_READ,
        SettingsVoter::SETTINGS_WRITE => self::SETTINGS_WRITE,
        // Users permissions
        UserVoter::USERS_ALL => self::USERS_ALL,
        UserVoter::USERS_READ => self::USERS_READ,
        UserVoter::USERS_WRITE => self::USERS_WRITE,
    ];

    public function __construct() {
        $this->permissions = [];
        $this->dictionaryPermissions = [];
    }

    public function setPermission(int $permissionType, bool $flag): void {
        $this->permissions[$permissionType] = $flag;
    }

    public function getDefaultPermissions(): array {
       return $this->permissions;
    }

    public function getPermission($permissionType): bool {
        return !empty($this->permissions[$permissionType]);
    }

    public function getPermissionsForWrite(): array {
        $result = $this->permissions;
        foreach (self::GROUPS as $allPerm => $subPerms) {
            if (!empty($result[$allPerm])) {
                foreach ($subPerms as $perm) {
                    unset($result[$perm]);
                }
            }
        }
        return $result;
    }

    public function setDictionaryPermission(RoleDictionaryPermissions $dictPermission) {
        $this->dictionaryPermissions[$dictPermission->dictionaryName] = $dictPermission;
    }

    public function getDictionaryPermissions($dictName) : ?RoleDictionaryPermissions {
        if (array_key_exists($dictName, $this->dictionaryPermissions)) {
            return $this->dictionaryPermissions[$dictName];
        }
        return null;
    }
}