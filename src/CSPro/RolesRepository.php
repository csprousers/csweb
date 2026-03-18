<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace App\CSPro;

use App\Service\PdoHelper;
use Psr\Log\LoggerInterface;
use App\CSPro\User\Role;
use App\CSPro\User\User;
use App\CSPro\User\RolePermissions;
use App\CSPro\User\RoleDictionaryPermissions;

/**
 * Description of RolesRepository
 *
 * @author savy
 */
class RolesRepository {

    public function __construct(private PdoHelper $pdo, private LoggerInterface $logger) {

    }

    public function getRoles() {
        $roles = $this->pdo->query('SELECT id, name FROM cspro_roles ORDER BY name')->fetchAll(PdoHelper::FETCH_CLASS, \App\CSPro\User\Role::class);
        $this->getRolePermissions($roles);
        return $roles;
    }

    public function getRoleById($roleId) {
        $role = null;

        $stm = $this->pdo->prepare('SELECT id, name FROM cspro_roles WHERE id=:role_id ORDER BY name');
        $stm->bindParam(':role_id', $roleId);
        $stm->execute();
        $roles = $stm->fetchAll(PdoHelper::FETCH_CLASS, \App\CSPro\User\Role::class);
        $this->getRolePermissions($roles);
        if (count($roles))
            $role = $roles[0];

        return $role;
    }

    public function getRolePermissions($roles) {
        //get role permissions for data, apps, users, roles and reports
        foreach ($roles as &$role) {
            $stm = 'SELECT permission_id as permission_id FROM cspro_role_permissions WHERE role_id = :roleId';
            $bind = ['roleId' => $role->id];
            $result = $this->pdo->fetchAll($stm, $bind);
            foreach ($result as $row) {
                $role->rolePermissions->setPermission($row['permission_id'], true);
            }
        }

        //get role dictionary permissions
        foreach ($roles as &$role) {
            $stm = 'SELECT id as dictionaryId, dictionary_label, name, cspro_role_dictionary_permissions.permission_id as permissionId,  role_id '
                    . 'FROM cspro_dictionaries LEFT JOIN cspro_role_dictionary_permissions ON dictionary_id = cspro_dictionaries.id '
                    . 'AND  role_id = :roleId  OR role_id IS NULL ORDER BY `name`';
            $bind = ['roleId' => $role->id];
            $result = $this->pdo->fetchAll($stm, $bind);
            $roleDictPermission = null;
            foreach ($result as $row) {
                $roleDictPermission = $role->rolePermissions->getDictionaryPermissions($row['name']);
                if (empty($row['role_id']) && !isset($roleDictPermission)) {// no granular permissions set default
                    $roleDictPermission = new RoleDictionaryPermissions($row['name'], $row['dictionaryId'], $row['dictionary_label'], true, null, $role->rolePermissions->getDefaultPermissions());
                    $role->rolePermissions->setDictionaryPermission($roleDictPermission);
                }
                elseif(!isset($roleDictPermission)){ //cspro_role_dictionary_permissions has an entry for granular permissions for this dictionary for this role
                    $roleDictPermission = new RoleDictionaryPermissions($row['name'], $row['dictionaryId'], $row['dictionary_label'], false, null, $role->rolePermissions->getDefaultPermissions());
                    $role->rolePermissions->setDictionaryPermission($roleDictPermission);
                }
                if(isset($row['permissionId'])) {//if the permission id is available set it in the permissions array
                    $roleDictPermission->permissions[$row['permissionId']] = true;
                }
            }
        }
        return $roles;
    }

    public function getNewRole() {
        //for each dictionary set blank syncload
        $role = new Role();
        //get dictionary names and ids
        try {
            $stm = 'SELECT id, dictionary_label, `name` FROM cspro_dictionaries ORDER BY `name`';
            $result = $this->pdo->fetchAll($stm);
            foreach ($result as $row) {
                $roleDictPermission = new RoleDictionaryPermissions($row['name'], $row['id'], $row['dictionary_label'], true);
                $role->rolePermissions->setDictionaryPermission($roleDictPermission);
            }
        } catch (\Exception $e) {
            throw new \Exception('Failed getting new role', 0, $e);
        }
        return $role;
    }

    public function addOrUpdateRole(Role $role, $add = true) : bool{
        $strMessage = $add ? 'Adding role $role->name' : "Updating role $role->name" ;
        $this->logger->debug($strMessage);
        if (isset($role->rolePermissions)) {
            try {
                $this->pdo->beginTransaction();
                if ($add) {
                    //insert a new role
                    $stm = 'INSERT INTO `cspro_roles`(`name`) VALUE(:roleName)';
                    $bind = ['roleName' => $role->name];
                    $this->pdo->perform($stm, $bind);
                    $role->id = $this->pdo->lastInsertId();
                }

                //delete the role permissions
                $this->logger->debug('Deleting exising role permissions' . $role->id);

                $stm = 'DELETE FROM cspro_role_permissions WHERE role_id = :roleId';
                $bind = ['roleId' => $role->id];
                $count = $this->pdo->fetchAffected($stm, $bind);

                $insertQuery = [];
                $arrPermissions = $role->rolePermissions->getPermissionsForWrite();
                foreach ($arrPermissions as $permissionType => $value) {
                    if ($value) {
                        $insertQuery [] = "({$role->id}, $permissionType)";
                    }
                }
                if (count($insertQuery)) {
                    $stm = 'INSERT INTO  cspro_role_permissions (role_id, permission_id) VALUES ';
                    $stm .= implode(', ', $insertQuery) . ';';
                    $this->pdo->fetchAffected($stm);
                }
                //refresh role dictionary permissions
                $stm = 'DELETE FROM cspro_role_dictionary_permissions WHERE role_id = :roleId';
                $bind = ['roleId' => $role->id];
                $count = $this->pdo->fetchAffected($stm, $bind);

                $stm = 'INSERT INTO  cspro_role_dictionary_permissions (role_id, dictionary_id, permission_id) VALUES ';

                $hasDictionaryPermissions = is_countable($role->rolePermissions->dictionaryPermissions) ? count($role->rolePermissions->dictionaryPermissions) : 0;
                if ($hasDictionaryPermissions) {
                    $insertQuery = [];
                    foreach ($role->rolePermissions->dictionaryPermissions as $key => $permission) {
                        if ($permission && !$permission->useDefault) {
                            $arrDictPermissions = $permission->getPermissionsForWrite();

                            foreach ($arrDictPermissions as $permissionType => $value) {
                                if ($value) {
                                    $insertQuery[] = "({$role->id}, {$permission->dictionaryId}, $permissionType)";
                                }
                            }
                        }
                    }
                    if (count($insertQuery)) {
                        $stm = 'INSERT INTO  cspro_role_dictionary_permissions (role_id, dictionary_id, permission_id) VALUES ';
                        $stm .= implode(', ', $insertQuery) . ';';
                        $this->pdo->fetchAffected($stm);
                    }
                }
                $this->pdo->commit();
                return true;
            } catch (\Exception $e) {
                $strMessage = $add ? "Failed adding role {$role->name} " : "Failed updating role {$role->name} " ;
                $this->pdo->rollBack();
                $this->logger->error($strMessage, ['context' => (string) $e]);
                throw new \Exception($strMessage . $e->getMessage(), 0, $e);
            }
        }
        return false;
    }

    public function deleteRole($roleId, $roleName) {
        try {
            $this->pdo->beginTransaction();
            $this->moveUsersToStandardRole($roleId, $roleName);
            $stm = 'DELETE FROM cspro_roles WHERE id = :roleId';
            $bind = ['roleId' => $roleId];
            $count = $this->pdo->fetchAffected($stm, $bind);
            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            $this->logger->error('Failed deleting role ' . $roleName, ['context' => (string) $e]);
            throw new \Exception('Failed deleting role: ' . $roleName, 0, $e);
        }
        return $count;
    }

    //move users under the roleName to standard users before role deletion
    public function moveUsersToStandardRole($roleId, $roleName) {
        try {
            $stm = 'UPDATE cspro_users SET role=' . User::STANDARD_USER . ' where role=:roleId';
            $bind = ['roleId' => $roleId];
            $this->pdo->fetchAffected($stm, $bind);
            $count = $this->pdo->fetchAffected($stm, $bind);
        } catch (\Exception $e) {
            $this->logger->error('Failed moving users to standardrole: ' . $roleName, ["context" => (string) $e]);
            throw new \Exception('Failed moving users to standardrole: ' . $roleName, 0, $e);
        }
        return $count;
    }
}
