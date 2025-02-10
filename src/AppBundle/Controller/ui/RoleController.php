<?php

namespace AppBundle\Controller\ui;

use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;
use AppBundle\Service\HttpHelper;
use AppBundle\Service\PdoHelper;
use AppBundle\CSPro\CSProResponse;
use AppBundle\CSPro\RolesRepository;
use AppBundle\CSPro\User\RolePermissions;
use AppBundle\CSPro\User\RoleDictionaryPermissions;
use AppBundle\CSPro\User\Role;

class RoleController extends AbstractController implements TokenAuthenticatedController {

    private $rolesRepository;

    public function __construct(private HttpHelper $client, private PdoHelper $pdo, private LoggerInterface $logger) {
        
    }

    //overrider the setcontainer to get access to container parameters and initiailize the roles repository
    public function setContainer(ContainerInterface $container = null): ?ContainerInterface {
        $this->rolesRepository = new RolesRepository($this->pdo, $this->logger);
        return parent::setContainer($container);
    }

    #[Route('/roles', name: 'roles', methods: ['GET'])]
    public function viewRoleListAction(Request $request): Response {

        $this->denyAccessUnlessGranted('ROLE_ROLES_ALL');
        return $this->render('roles.twig', []);
    }

    #[Route('/getRoles', name: 'get-roles', methods: ['GET'])]
    public function getRoles(Request $request): Response {

        $this->denyAccessUnlessGranted('ROLE_ROLES_ALL');

        $roles = $this->rolesRepository->getRoles();
        $this->logger->debug((is_countable($roles) ? count($roles) : 0) . ' the roles are' . json_encode($roles, JSON_THROW_ON_ERROR));

        $response = new Response(json_encode($roles, JSON_THROW_ON_ERROR));
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    #[Route('/getDictionaryPermissions', name: 'get-dictionary-permissions', methods: ['GET'])]
    public function getDictionaryPermissions(Request $request): Response {
        $this->denyAccessUnlessGranted('ROLE_ROLES_ALL');

        $rowNumber = $request->get('rowNumber');

        if (isset($rowNumber)) {
            $roles = $this->rolesRepository->getRoles();
            //$this->logger->error('getRoles() = ' . print_r($roles, true));
            // Get dictionaryPermissions for the role at rowNumber
            $dictionaryPermissions = $roles[$rowNumber]->rolePermissions->dictionaryPermissions;
        } else {
            $role = $this->rolesRepository->getNewRole();
            $dictionaryPermissions = $role->rolePermissions->dictionaryPermissions;
        }

        $indexedDictionaryPermissions = [];
        foreach ($dictionaryPermissions as $dp) {
            // Convert associative array to indexed array, so DataTable's row container is indexed
            $indexedDictionaryPermissions[] = $dp;
        }

        $response = new Response(json_encode($indexedDictionaryPermissions, JSON_THROW_ON_ERROR));
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

    #[Route('/addRole', name: 'add-role', methods: ['POST'])]
    public function addRole(Request $request): CSProResponse {
        $this->denyAccessUnlessGranted('ROLE_ROLES_ALL');

        $roleName = $request->get('roleName');
        $dataPermission = $request->get('dataPermission');
        $reportsPermission = $request->get('reportsPermission');
        $appsPermission = $request->get('appsPermission');
        $usersPermission = $request->get('usersPermission');
        $rolesPermission = $request->get('rolesPermission');
        $dictionaryPermissions = $request->get('dictionaryPermissions');
        $settingsPermission = $request->get('settingsPermissions');

        is_array($dictionaryPermissions) ?: $dictionaryPermissions = [];

        $role = new Role();
        $role->name = $roleName;
        $dataPermission === "true" ? $role->rolePermissions->setPermission(RolePermissions::DATA_ALL, true) : $role->rolePermissions->setPermission(RolePermissions::DATA_ALL, false);
        $reportsPermission === "true" ? $role->rolePermissions->setPermission(RolePermissions::REPORTS_ALL, true) : $role->rolePermissions->setPermission(RolePermissions::REPORTS_ALL, false);
        $appsPermission === "true" ? $role->rolePermissions->setPermission(RolePermissions::APPS_ALL, true) : $role->rolePermissions->setPermission(RolePermissions::APPS_ALL, false);
        $usersPermission === "true" ? $role->rolePermissions->setPermission(RolePermissions::USERS_ALL, true) : $role->rolePermissions->setPermission(RolePermissions::USERS_ALL, false);
        $rolesPermission === "true" ? $role->rolePermissions->setPermission(RolePermissions::ROLES_ALL, true) : $role->rolePermissions->setPermission(RolePermissions::ROLES_ALL, false);
        $settingsPermission === "true" ? $role->rolePermissions->setPermission(RolePermissions::SETTINGS_ALL, true) : $role->rolePermissions->setPermission(RolePermissions::SETTINGS_ALL, false);

        foreach ($dictionaryPermissions as $dp) {
            $roleDictPermission = new RoleDictionaryPermissions($dp['dictionaryname'], $dp['dictionaryId'], $dp['syncUpload'], $dp['syncDownload'], $dp['dictionarylabel']);
            $role->rolePermissions->setDictionaryPermission($roleDictPermission);
        }

        try {
            $result = $this->rolesRepository->addRole($role);
            if ($result === true)
                return new CSProResponse(json_encode("Added $roleName", JSON_THROW_ON_ERROR), CSProResponse::HTTP_OK);
            else
                return new CSProResponse(json_encode("Failed to add $roleName", JSON_THROW_ON_ERROR), CSProResponse::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Exception $e) {
            $result['code'] = 500;
            $duplicateErrMsg = 'Integrity constraint violation: 1062';
            if (strpos($e->getMessage(), $duplicateErrMsg)) {
                $result['description'] = "Role name `$roleName` already in use";
            } else {
                $result['description'] = "Failed to add role: $roleName";
            }
            $response = new CSProResponse();
            $response->setError($result['code'], 'add_role_error', $result ['description']);
            return $response;
        }
    }

    #[Route('/editRole', name: 'edit-role', methods: ['POST'])]
    public function editRole(Request $request): CSProResponse {
        $this->denyAccessUnlessGranted('ROLE_ROLES_ALL');

        $roleId = $request->get('roleId');
        $roleName = $request->get('roleName');
        $dataPermission = $request->get('dataPermission');
        $reportsPermission = $request->get('reportsPermission');
        $appsPermission = $request->get('appsPermission');
        $usersPermission = $request->get('usersPermission');
        $rolesPermission = $request->get('rolesPermission');
        $dictionaryPermissions = $request->get('dictionaryPermissions');
        $settingsPermission = $request->get('settingsPermission');

        $role = new Role();
        $role->name = $roleName;
        $role->id = $roleId;
        $dataPermission === "true" ? $role->rolePermissions->setPermission(RolePermissions::DATA_ALL, true) : $role->rolePermissions->setPermission(RolePermissions::DATA_ALL, false);
        $reportsPermission === "true" ? $role->rolePermissions->setPermission(RolePermissions::REPORTS_ALL, true) : $role->rolePermissions->setPermission(RolePermissions::REPORTS_ALL, false);
        $appsPermission === "true" ? $role->rolePermissions->setPermission(RolePermissions::APPS_ALL, true) : $role->rolePermissions->setPermission(RolePermissions::APPS_ALL, false);
        $usersPermission === "true" ? $role->rolePermissions->setPermission(RolePermissions::USERS_ALL, true) : $role->rolePermissions->setPermission(RolePermissions::USERS_ALL, false);
        $rolesPermission === "true" ? $role->rolePermissions->setPermission(RolePermissions::ROLES_ALL, true) : $role->rolePermissions->setPermission(RolePermissions::ROLES_ALL, false);
        $settingsPermission === "true" ? $role->rolePermissions->setPermission(RolePermissions::SETTINGS_ALL, true) : $role->rolePermissions->setPermission(RolePermissions::SETTINGS_ALL, false);

        foreach ($dictionaryPermissions as $dp) {
            //$this->logger->error($dp['dictionaryname']);
            //$this->logger->error($dp['dictionaryId']);
            //$this->logger->error($dp['syncUpload']);
            //$this->logger->error($dp['syncDownload']);

            $roleDictPermission = new RoleDictionaryPermissions($dp['dictionaryname'], $dp['dictionaryId'], $dp['syncUpload'], $dp['syncDownload'], $dp['dictionarylabel']);
            $role->rolePermissions->setDictionaryPermission($roleDictPermission);
            //$this->logger->error('Check dictionary permissions: ' . print_r($role->rolePermissions->getDictionaryPermissions($dp['dictionaryname']), true));
        }

        try {
            // $this->logger->debug('printing role ' . print_r($role, true));
            $result = $this->rolesRepository->saveRole($role);
            if ($result === true)
                return new CSProResponse(json_encode("Saved permissions for $roleName", JSON_THROW_ON_ERROR), CSProResponse::HTTP_OK);
            else
                return new CSProResponse(json_encode("Failed to save permissions for $roleName", JSON_THROW_ON_ERROR), CSProResponse::HTTP_INTERNAL_SERVER_ERROR);
        } catch (\Exception) {
            $result['code'] = 500;
            $result['description'] = "Failed to save permissions for $roleName";
            $response = new CSProResponse();
            $response->setError($result['code'], 'save_permissions_error', $result ['description']);
            return $response;
        }
    }

    #[Route('/deleteRole', name: 'delete-role', methods: ['DELETE'])]
    public function DeleteRole(Request $request): Response {

        $this->denyAccessUnlessGranted('ROLE_ROLES_ALL');

        $result = [];
        $roleName = $request->get('roleName');
        $roleId = $request->get('roleId');

        $count = $this->rolesRepository->deleteRole($roleId, $roleName);

        if ($count) {
            $result['description'] = 'Deleted role ' . $roleName;
            $this->logger->debug($result['description']);
            $response = new Response(json_encode($result['description'], JSON_THROW_ON_ERROR), Response::HTTP_OK);
        } else {
            $result['description'] = 'Failed deleting role ' . $roleName;
            $response = new Response(json_encode($result['description'], JSON_THROW_ON_ERROR), Response::HTTP_NOT_FOUND);
        }
        $response->headers->set('Content-Length', strlen($response->getContent()));
        return $response;
    }

}
